<?php

namespace App\GameProtocol\Support;

use App\Enums\TaikoGameVersion;
use App\Models\Song;
use App\Models\SongBest;

class ScoreMapper
{
    /**
     * Pre-RED Taiko dialects (sorairo..murasaki) build their entire in-game
     * song list from the songhash.php `song_hash_tbl` and gate availability with
     * a packed unlock flag, instead of the song_no-indexed bitfield newer
     * versions use. See {@see self::legacySongHashTable()}.
     *
     * @var list<value-of<TaikoGameVersion>>
     */
    private const LEGACY_VERSIONS = [
        TaikoGameVersion::Katsudon->value,
        TaikoGameVersion::Sorairo->value,
        TaikoGameVersion::Momoiro->value,
        TaikoGameVersion::Kimidori->value,
        TaikoGameVersion::Murasaki->value,
    ];

    public function rankForScore(int $score): int
    {
        return match (true) {
            $score >= 1000000 => 8,
            $score >= 950000 => 7,
            $score >= 900000 => 6,
            $score >= 800000 => 5,
            $score >= 700000 => 4,
            $score >= 600000 => 3,
            $score >= 500000 => 2,
            default => 1,
        };
    }

    public function emptyFlagBytes(int $bytes = 512): string
    {
        return str_repeat("\0", $bytes);
    }

    /**
     * Crown rank reported by the cabinet in a stage's play_result.
     */
    private const CROWN_NONE = 0;

    private const CROWN_CLEAR = 1;

    private const CROWN_GOLD = 2;

    private const CROWN_DONDAFUL = 3;

    /**
     * Map a stored crown rank to the 2-bit wire state the cabinet expects in
     * the crown flag bitfield: clear => 2, gold/dondaful => 3, else 0.
     */
    public function crownWireState(int $crown): int
    {
        return match ($crown) {
            self::CROWN_CLEAR => 2,
            self::CROWN_GOLD, self::CROWN_DONDAFUL => 3,
            default => 0,
        };
    }

    /**
     * Build the gzip-compressed crown flag bitfield for a player's best rows.
     *
     * Each song occupies 10 consecutive bits keyed by its song_no (2 bits per
     * difficulty, easy..ura). The inflated buffer is 1280 bytes (1024 songs),
     * then gzip-compressed as the cabinet expects for hash_crown_flg.
     *
     * @param  iterable<array{song_no: int, level: int, best_crown: int}|SongBest>  $bests
     */
    public function crownFlagBytes(iterable $bests, ?string $gameVersion = null): string
    {
        if ($gameVersion !== null && $this->isLegacySongList($gameVersion)) {
            $songNumbers = Song::query()
                ->where('version', $gameVersion)
                ->pluck('song_no')
                ->map(fn (mixed $songNo): int => (int) $songNo);

            $orderedSongs = $this->legacySongOrder($songNumbers);
            $count = count($orderedSongs);

            if ($count === 0) {
                return '';
            }

            $songIndexMap = array_flip($orderedSongs);
            $values = array_fill(0, $count, 0);

            foreach ($bests as $best) {
                $songNo = (int) $best->song_no;
                if (! isset($songIndexMap[$songNo])) {
                    continue;
                }

                $level = (int) $best->level;
                $slot = $level - 1;

                if ($slot < 0 || $slot > 4) {
                    continue;
                }

                $state = $this->crownWireState((int) $best->best_crown);
                if ($state === 0) {
                    continue;
                }

                $songIndex = $songIndexMap[$songNo];
                $values[$songIndex] |= ($state & 0x03) << ($slot * 2);
            }

            $buffer = array_fill(0, intdiv($count * 10 + 7, 8), 0);

            foreach ($values as $songIndex => $value) {
                $value &= 0x03FF;
                if ($value === 0) {
                    continue;
                }

                $bitOffset = $songIndex * 10;
                for ($bit = 0; $bit < 10; $bit++) {
                    if (($value & (1 << $bit)) === 0) {
                        continue;
                    }

                    $absoluteBit = $bitOffset + $bit;
                    $buffer[$absoluteBit >> 3] |= 1 << ($absoluteBit & 7);
                }
            }

            return implode('', array_map('chr', $buffer));
        }

        $values = array_fill(0, 1024, 0);

        foreach ($bests as $best) {
            $songNo = (int) $best->song_no;
            $level = (int) $best->level;
            $slot = $level - 1;

            if ($songNo < 0 || $songNo >= 1024 || $slot < 0 || $slot > 4) {
                continue;
            }

            $state = $this->crownWireState((int) $best->best_crown);
            if ($state === 0) {
                continue;
            }

            $values[$songNo] |= ($state & 0x03) << ($slot * 2);
        }

        $buffer = array_fill(0, 1280, 0);

        foreach ($values as $songIndex => $value) {
            $value &= 0x03FF;
            if ($value === 0) {
                continue;
            }

            $bitOffset = $songIndex * 10;
            for ($bit = 0; $bit < 10; $bit++) {
                if (($value & (1 << $bit)) === 0) {
                    continue;
                }

                $absoluteBit = $bitOffset + $bit;
                $buffer[$absoluteBit >> 3] |= 1 << ($absoluteBit & 7);
            }
        }

        return gzencode(implode('', array_map('chr', $buffer)), 9);
    }

    /**
     * Build a fixed-size bitset where each unlocked id maps directly to its bit
     * (id 0 => byte 0 bit 0). Used for tone/title/costume unlock flags, which —
     * unlike song flags — are not offset by one.
     *
     * @param  iterable<int>  $ids
     */
    public function idFlagBytes(iterable $ids, int $bytes): string
    {
        $flags = array_fill(0, $bytes, 0);
        $maxBits = $bytes * 8;

        foreach ($ids as $id) {
            $id = (int) $id;

            if ($id < 0 || $id >= $maxBits) {
                continue;
            }

            $flags[$id >> 3] |= 1 << ($id & 7);
        }

        return implode('', array_map(static fn (int $flag): string => chr($flag), $flags));
    }

    /**
     * Decode a fixed-size bitset back into a list of unlocked ids.
     *
     * @return array<int, int>
     */
    public function flagBytesToIds(string $bytes): array
    {
        $ids = [];
        $length = strlen($bytes);

        for ($i = 0; $i < $length; $i++) {
            $byte = ord($bytes[$i]);
            if ($byte === 0) {
                continue;
            }

            for ($bit = 0; $bit < 8; $bit++) {
                if (($byte & (1 << $bit)) !== 0) {
                    $ids[] = $i * 8 + $bit;
                }
            }
        }

        return $ids;
    }

    /**
     * Whether this dialect drives its song list through the legacy songhash
     * table + packed unlock flag rather than the song_no-indexed bitfield.
     */
    public function isLegacySongList(string $gameVersion): bool
    {
        return in_array($gameVersion, self::LEGACY_VERSIONS, true);
    }

    /**
     * Distinct song numbers for a legacy dialect, ascending. The songhash table
     * and the packed unlock flag must share this ordering, since the cabinet
     * keys the unlock flag by each song's position in the songhash table.
     *
     * @param  iterable<int>  $songNumbers
     * @return list<int>
     */
    private function legacySongOrder(iterable $songNumbers): array
    {
        $numbers = [];

        foreach ($songNumbers as $songNo) {
            $songNo = (int) $songNo;

            if ($songNo >= 0 && $songNo <= 0xFFFF) {
                $numbers[$songNo] = $songNo;
            }
        }

        ksort($numbers);

        return array_values($numbers);
    }

    /**
     * Build the legacy `song_hash_tbl`: a big-endian uint16 per song, in
     * ascending song_no order. The cabinet derives its song count from the
     * length of this blob (bytes / 2) and uses each value as the song's slot in
     * the unlock bitfield, so an empty table means "no songs at all".
     *
     * @param  iterable<int>  $songNumbers
     */
    public function legacySongHashTable(iterable $songNumbers): string
    {
        $bytes = '';

        foreach ($this->legacySongOrder($songNumbers) as $songNo) {
            $bytes .= pack('n', $songNo);
        }

        return $bytes;
    }

    /**
     * @param  iterable<int>  $songNumbers
     */
    public function releaseSongFlagBytes(string $gameVersion, iterable $songNumbers): string
    {
        if ($this->isLegacySongList($gameVersion)) {
            // One bit per song, packed in songhash-table order; every song
            // unlocked by default. Length tracks the song count so it lines up
            // with the cabinet's song table.
            $count = count($this->legacySongOrder($songNumbers));

            return str_repeat("\xFF", intdiv($count + 7, 8));
        }

        return $this->songFlagBytes($songNumbers);
    }

    /**
     * Build Katsu Don's 64-byte default-song bitset.
     *
     * Unlike later song flags, the bit index is the song's unique_id directly:
     * unique_id 0 => byte 0 bit 0, with no one-based adjustment. Katsu Don's
     * catalog uses even unique ids, but the direct mapping is intentional.
     *
     * @param  iterable<int>  $uniqueIds
     */
    public function katsudonDefaultSongFlagBytes(iterable $uniqueIds): string
    {
        return $this->idFlagBytes($uniqueIds, 64);
    }

    /**
     * @param  iterable<int>  $songNumbers
     */
    public function songFlagBytes(iterable $songNumbers, int $bytes = 512): string
    {
        $flags = array_fill(0, $bytes, 0);

        foreach ($songNumbers as $songNo) {
            $songNo = (int) $songNo;

            if ($songNo < 1) {
                continue;
            }

            $bitIndex = $songNo - 1;
            $byteIndex = intdiv($bitIndex, 8);

            if ($byteIndex >= $bytes) {
                continue;
            }

            $flags[$byteIndex] |= 1 << ($bitIndex % 8);
        }

        return implode('', array_map(static fn (int $flag): string => chr($flag), $flags));
    }
}
