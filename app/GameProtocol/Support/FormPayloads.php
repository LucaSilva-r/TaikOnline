<?php

namespace App\GameProtocol\Support;

class FormPayloads
{
    /**
     * @return array<string, string>
     */
    public function decodeAllNetRequest(string $payload): array
    {
        $compressed = base64_decode(trim($payload), true);
        if ($compressed === false) {
            return $this->decodePlainForm($payload);
        }

        $decompressed = @gzuncompress($compressed);
        if ($decompressed === false) {
            $decompressed = @gzinflate($compressed);
        }

        if ($decompressed === false) {
            return $this->decodePlainForm($payload);
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

    /**
     * @return array<string, string>
     */
    private function decodePlainForm(string $payload): array
    {
        if (! str_contains($payload, '=')) {
            return [];
        }

        parse_str($payload, $values);

        return $this->stringValues($values);
    }
}
