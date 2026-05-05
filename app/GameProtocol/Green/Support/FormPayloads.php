<?php

namespace App\GameProtocol\Green\Support;

class FormPayloads
{
    /**
     * @return array<string, string>
     */
    public function decodeAllNetRequest(string $payload): array
    {
        if (str_contains($payload, '=')) {
            parse_str($payload, $values);

            return $this->stringValues($values);
        }

        $compressed = base64_decode(trim($payload), true);
        if ($compressed === false) {
            return [];
        }

        $decompressed = @gzuncompress($compressed);
        if ($decompressed === false) {
            $decompressed = @gzinflate($compressed);
        }

        if ($decompressed === false) {
            return [];
        }

        parse_str($decompressed, $values);

        return $this->stringValues($values);
    }

    /**
     * @param  array<string, scalar|null>  $values
     */
    public function encode(array $values): string
    {
        return collect($values)
            ->map(fn ($value, string $key): string => $key.'='.(string) $value)
            ->implode('&');
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    private function stringValues(array $values): array
    {
        return collect($values)
            ->mapWithKeys(fn (mixed $value, string $key): array => [$key => is_scalar($value) ? (string) $value : ''])
            ->all();
    }
}
