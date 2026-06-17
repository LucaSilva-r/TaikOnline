<?php

namespace App\Support;

class SongSearch
{
    /**
     * Build the searchable text for a song title: a lowercased, halfwidth form
     * of the title (so fullwidth latin and Japanese both match) followed by its
     * kana romaji, so players can search without a Japanese keyboard.
     */
    public static function indexFor(string $title): string
    {
        $normalized = self::normalize($title);
        $romaji = self::normalize(KanaRomaji::toRomaji($title));

        return trim("{$normalized} {$romaji}");
    }

    /**
     * Normalise a string for matching: fold fullwidth ASCII to halfwidth and
     * lowercase. Kana and kanji are left untouched.
     */
    public static function normalize(string $value): string
    {
        $result = '';
        foreach (mb_str_split($value) as $char) {
            $code = mb_ord($char, 'UTF-8');
            if ($code !== false && $code >= 0xFF01 && $code <= 0xFF5E) {
                $result .= mb_chr($code - 0xFEE0, 'UTF-8');

                continue;
            }
            // Fullwidth space to a regular space.
            if ($code === 0x3000) {
                $result .= ' ';

                continue;
            }
            $result .= $char;
        }

        return mb_strtolower(trim($result));
    }
}
