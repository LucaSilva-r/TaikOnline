<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

class MifareAccessCodeService
{
    private const RECORD_BYTES = 0x48;

    private const MAX_SUFFIX = '99999999999999999';

    public function generate(?int $profile = null, ?int $cardId = null): string
    {
        $records = $this->records();

        for ($attempt = 0; $attempt < 1000; $attempt++) {
            $profileIndex = $profile ?? random_int(0, count($records) - 1);
            $record = $records[$profileIndex] ?? null;

            if ($record === null) {
                throw new RuntimeException('NBGIC profile is not configured.');
            }

            $candidateCardId = $cardId ?? random_int(0, 0xFFFFFFFF);
            $suffix = $this->suffixFor($record, $candidateCardId);

            if ($this->isValidSuffix($suffix)) {
                return $this->prefix($record).str_pad($suffix, 17, '0', STR_PAD_LEFT);
            }

            if ($profile !== null || $cardId !== null) {
                break;
            }
        }

        throw new RuntimeException('Unable to generate a MIFARE-encodable access code.');
    }

    public function isEncodable(string $accessCode): bool
    {
        return $this->invert($accessCode) !== null;
    }

    /**
     * @return array{profile: int, card_id: int}|null
     */
    public function invert(string $accessCode): ?array
    {
        if (! preg_match('/^[0-9]{20}$/', $accessCode)) {
            return null;
        }

        foreach ($this->records() as $profile => $record) {
            if (! str_starts_with($accessCode, $this->prefix($record))) {
                continue;
            }

            $decimal = $this->decimalToU64(substr($accessCode, 3));
            $add = $this->readU64($record, 0x40);
            $permuted = $this->subU64($decimal, $add);
            $permutedBits = $this->low56Bits($permuted);

            $packed = array_fill(0, 7, 0);
            $permutation = array_values(unpack('C*', substr($record, 0x08, 56)));

            for ($src = 0; $src < 56; $src++) {
                $dst = $permutation[$src] % 56;
                if ($this->getBit($permutedBits, $dst) === 1) {
                    $this->setBit($packed, $src);
                }
            }

            $profileId = $this->readU32($record, 0);
            $pidField = $this->getBits($packed, 2, 10);
            $expectedPid = (($profileId >> 2) & 0xFF) | (($profileId & 3) << 8);
            $zero = $this->getBits($packed, 12, 4);
            $cardId = $this->bswap32($this->getBits($packed, 16, 32));
            $xor = $this->getBits($packed, 48, 8);
            $expectedXor = $this->xorBytes($profileId) ^ $this->xorBytes($cardId);
            $check2 = ($expectedXor - 3 * intdiv($expectedXor, 11)) & 3;

            if (
                $this->getBits($packed, 0, 2) === $check2
                && $pidField === $expectedPid
                && $zero === 0
                && $xor === $expectedXor
            ) {
                return ['profile' => $profile, 'card_id' => $cardId];
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function records(): array
    {
        $value = config('taiko_green.nbgic_profile_records');

        if ($value === null || $value === '') {
            throw new RuntimeException('NBGIC profile records are not configured.');
        }

        if (is_array($value)) {
            return array_map(fn (mixed $record): string => $this->decodeRecord((string) $record), array_values($value));
        }

        $string = (string) $value;
        $parts = array_values(array_filter(array_map('trim', explode(',', $string)), fn (string $part): bool => $part !== ''));

        if (count($parts) === 8) {
            return array_map(fn (string $record): string => $this->decodeRecord($record), $parts);
        }

        $bytes = $this->decodeBytes($string, self::RECORD_BYTES * 8);

        return str_split($bytes, self::RECORD_BYTES);
    }

    private function decodeRecord(string $value): string
    {
        return $this->decodeBytes($value, self::RECORD_BYTES);
    }

    private function decodeBytes(string $value, int $expectedLength): string
    {
        $hex = preg_replace('/[^0-9A-Fa-f]/', '', $value) ?? '';

        if (strlen($hex) === $expectedLength * 2) {
            $bytes = hex2bin($hex);
            if ($bytes !== false) {
                return $bytes;
            }
        }

        $decoded = base64_decode($value, true);
        if ($decoded !== false && strlen($decoded) === $expectedLength) {
            return $decoded;
        }

        throw new RuntimeException("NBGIC profile data must decode to {$expectedLength} bytes.");
    }

    private function suffixFor(string $record, int $cardId): string
    {
        $profileId = $this->readU32($record, 0);
        $xor = $this->xorBytes($profileId) ^ $this->xorBytes($cardId);
        $check2 = ($xor - 3 * intdiv($xor, 11)) & 3;

        $packed = array_fill(0, 7, 0);
        $this->setBits($packed, 0, 2, $check2);
        $this->setBits($packed, 2, 10, (($profileId >> 2) & 0xFF) | (($profileId & 3) << 8));
        $this->setBits($packed, 12, 4, 0);
        $this->setBits($packed, 16, 32, $this->bswap32($cardId));
        $this->setBits($packed, 48, 8, $xor);

        $permuted = array_fill(0, 7, 0);
        $permutation = array_values(unpack('C*', substr($record, 0x08, 56)));

        for ($src = 0; $src < 56; $src++) {
            if ($this->getBit($packed, $src) === 1) {
                $this->setBit($permuted, $permutation[$src] % 56);
            }
        }

        return $this->u64ToDecimal($this->addU64($this->u56ToInt($permuted), $this->readU64($record, 0x40)));
    }

    private function prefix(string $record): string
    {
        return substr($record, 4, 3);
    }

    private function readU32(string $bytes, int $offset): int
    {
        return Arr::first(unpack('N', substr($bytes, $offset, 4)));
    }

    /**
     * @return array{hi: int, lo: int}
     */
    private function readU64(string $bytes, int $offset): array
    {
        $parts = unpack('Nhi/Nlo', substr($bytes, $offset, 8));

        return ['hi' => (int) $parts['hi'], 'lo' => (int) $parts['lo']];
    }

    private function bswap32(int $value): int
    {
        return (($value & 0xFF) << 24)
            | (($value & 0xFF00) << 8)
            | (($value >> 8) & 0xFF00)
            | (($value >> 24) & 0xFF);
    }

    private function xorBytes(int $value): int
    {
        return (($value >> 24) & 0xFF)
            ^ (($value >> 16) & 0xFF)
            ^ (($value >> 8) & 0xFF)
            ^ ($value & 0xFF);
    }

    /**
     * @param  array<int, int>  $bytes
     */
    private function getBit(array $bytes, int $position): int
    {
        return ($bytes[intdiv($position, 8)] >> (7 - ($position & 7))) & 1;
    }

    /**
     * @param  array<int, int>  $bytes
     */
    private function setBit(array &$bytes, int $position): void
    {
        $bytes[intdiv($position, 8)] |= 1 << (7 - ($position & 7));
    }

    /**
     * @param  array<int, int>  $bytes
     */
    private function setBits(array &$bytes, int $offset, int $width, int $value): void
    {
        for ($i = 0; $i < $width; $i++) {
            if ((($value >> ($width - $i - 1)) & 1) === 1) {
                $this->setBit($bytes, $offset + $i);
            }
        }
    }

    /**
     * @param  array<int, int>  $bytes
     */
    private function getBits(array $bytes, int $offset, int $width): int
    {
        $value = 0;
        for ($i = 0; $i < $width; $i++) {
            $value = ($value << 1) | $this->getBit($bytes, $offset + $i);
        }

        return $value;
    }

    /**
     * @param  array<int, int>  $bytes
     */
    private function u56ToInt(array $bytes): int
    {
        $value = 0;
        foreach ($bytes as $byte) {
            $value = ($value << 8) | $byte;
        }

        return $value;
    }

    /**
     * @return array{hi: int, lo: int}
     */
    private function addU64(int $left, array $right): array
    {
        $leftHi = intdiv($left, 0x100000000);
        $leftLo = $left & 0xFFFFFFFF;
        $lo = $leftLo + $right['lo'];
        $carry = intdiv($lo, 0x100000000);

        return [
            'hi' => ($leftHi + $right['hi'] + $carry) & 0xFFFFFFFF,
            'lo' => $lo & 0xFFFFFFFF,
        ];
    }

    /**
     * @param  array{hi: int, lo: int}  $left
     * @param  array{hi: int, lo: int}  $right
     * @return array{hi: int, lo: int}
     */
    private function subU64(array $left, array $right): array
    {
        $borrow = $left['lo'] < $right['lo'] ? 1 : 0;

        return [
            'hi' => ($left['hi'] - $right['hi'] - $borrow) & 0xFFFFFFFF,
            'lo' => ($left['lo'] - $right['lo']) & 0xFFFFFFFF,
        ];
    }

    /**
     * @return array<int, int>
     */
    private function low56Bits(array $value): array
    {
        return [
            ($value['hi'] >> 16) & 0xFF,
            ($value['hi'] >> 8) & 0xFF,
            $value['hi'] & 0xFF,
            ($value['lo'] >> 24) & 0xFF,
            ($value['lo'] >> 16) & 0xFF,
            ($value['lo'] >> 8) & 0xFF,
            $value['lo'] & 0xFF,
        ];
    }

    /**
     * @return array{hi: int, lo: int}
     */
    private function decimalToU64(string $decimal): array
    {
        $value = ['hi' => 0, 'lo' => 0];

        foreach (str_split($decimal) as $digit) {
            $n = (int) $digit;
            $lo = $value['lo'] * 10 + $n;
            $carry = intdiv($lo, 0x100000000);
            $value = [
                'hi' => ($value['hi'] * 10 + $carry) & 0xFFFFFFFF,
                'lo' => $lo & 0xFFFFFFFF,
            ];
        }

        return $value;
    }

    /**
     * @param  array{hi: int, lo: int}  $value
     */
    private function u64ToDecimal(array $value): string
    {
        if ($value['hi'] === 0 && $value['lo'] === 0) {
            return '0';
        }

        $digits = '';
        while ($value['hi'] !== 0 || $value['lo'] !== 0) {
            $quotientHi = intdiv($value['hi'], 10);
            $remainder = $value['hi'] % 10;
            $combined = $remainder * 0x100000000 + $value['lo'];
            $quotientLo = intdiv($combined, 10);
            $digits .= (string) ($combined % 10);
            $value = ['hi' => $quotientHi, 'lo' => $quotientLo];
        }

        return Str::reverse($digits);
    }

    private function isValidSuffix(string $suffix): bool
    {
        return strlen($suffix) <= 17
            && (strlen($suffix) < 17 || strcmp($suffix, self::MAX_SUFFIX) <= 0);
    }
}
