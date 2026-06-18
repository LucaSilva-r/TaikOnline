<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Handles Postgres bytea columns transparently.
 *
 * PDO binds all string parameters as text, so raw binary passed to a bytea
 * column is misinterpreted. Writing as '\x' + hex makes Postgres's bytea
 * input function parse it correctly. Reading converts the stream resource
 * Postgres returns into a plain PHP binary string.
 */
class PostgresBytea implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_resource($value)) {
            rewind($value);

            return stream_get_contents($value);
        }

        $str = (string) $value;

        // Decode the hex-escaped format produced by set() so that reading the
        // attribute back after assignment gives the original binary string.
        if (str_starts_with($str, '\x')) {
            return hex2bin(substr($str, 2));
        }

        return $str;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return '\x'.bin2hex($value);
    }
}
