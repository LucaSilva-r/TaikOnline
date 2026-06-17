<?php

namespace App\Support;

/**
 * Deterministic, offline Hepburn-style transliteration of Japanese kana to
 * romaji. Katakana is folded onto hiragana first; kanji and any non-kana
 * characters are passed through untouched (we have no reading data for them).
 */
class KanaRomaji
{
    /**
     * Combination kana (palatalised / -ya -yu -yo) which must be matched before
     * the single-kana table.
     *
     * @var array<string, string>
     */
    private const COMBOS = [
        'きゃ' => 'kya', 'きゅ' => 'kyu', 'きょ' => 'kyo',
        'ぎゃ' => 'gya', 'ぎゅ' => 'gyu', 'ぎょ' => 'gyo',
        'しゃ' => 'sha', 'しゅ' => 'shu', 'しょ' => 'sho',
        'じゃ' => 'ja', 'じゅ' => 'ju', 'じょ' => 'jo',
        'ちゃ' => 'cha', 'ちゅ' => 'chu', 'ちょ' => 'cho',
        'ぢゃ' => 'ja', 'ぢゅ' => 'ju', 'ぢょ' => 'jo',
        'にゃ' => 'nya', 'にゅ' => 'nyu', 'にょ' => 'nyo',
        'ひゃ' => 'hya', 'ひゅ' => 'hyu', 'ひょ' => 'hyo',
        'びゃ' => 'bya', 'びゅ' => 'byu', 'びょ' => 'byo',
        'ぴゃ' => 'pya', 'ぴゅ' => 'pyu', 'ぴょ' => 'pyo',
        'みゃ' => 'mya', 'みゅ' => 'myu', 'みょ' => 'myo',
        'りゃ' => 'rya', 'りゅ' => 'ryu', 'りょ' => 'ryo',
    ];

    /**
     * @var array<string, string>
     */
    private const SINGLES = [
        'あ' => 'a', 'い' => 'i', 'う' => 'u', 'え' => 'e', 'お' => 'o',
        'か' => 'ka', 'き' => 'ki', 'く' => 'ku', 'け' => 'ke', 'こ' => 'ko',
        'が' => 'ga', 'ぎ' => 'gi', 'ぐ' => 'gu', 'げ' => 'ge', 'ご' => 'go',
        'さ' => 'sa', 'し' => 'shi', 'す' => 'su', 'せ' => 'se', 'そ' => 'so',
        'ざ' => 'za', 'じ' => 'ji', 'ず' => 'zu', 'ぜ' => 'ze', 'ぞ' => 'zo',
        'た' => 'ta', 'ち' => 'chi', 'つ' => 'tsu', 'て' => 'te', 'と' => 'to',
        'だ' => 'da', 'ぢ' => 'ji', 'づ' => 'zu', 'で' => 'de', 'ど' => 'do',
        'な' => 'na', 'に' => 'ni', 'ぬ' => 'nu', 'ね' => 'ne', 'の' => 'no',
        'は' => 'ha', 'ひ' => 'hi', 'ふ' => 'fu', 'へ' => 'he', 'ほ' => 'ho',
        'ば' => 'ba', 'び' => 'bi', 'ぶ' => 'bu', 'べ' => 'be', 'ぼ' => 'bo',
        'ぱ' => 'pa', 'ぴ' => 'pi', 'ぷ' => 'pu', 'ぺ' => 'pe', 'ぽ' => 'po',
        'ま' => 'ma', 'み' => 'mi', 'む' => 'mu', 'め' => 'me', 'も' => 'mo',
        'や' => 'ya', 'ゆ' => 'yu', 'よ' => 'yo',
        'ら' => 'ra', 'り' => 'ri', 'る' => 'ru', 'れ' => 're', 'ろ' => 'ro',
        'わ' => 'wa', 'を' => 'o', 'ん' => 'n',
        'ゔ' => 'vu', 'ゎ' => 'wa',
        // Small vowels (when standing alone).
        'ぁ' => 'a', 'ぃ' => 'i', 'ぅ' => 'u', 'ぇ' => 'e', 'ぉ' => 'o',
        'ゃ' => 'ya', 'ゅ' => 'yu', 'ょ' => 'yo',
    ];

    public static function toRomaji(string $text): string
    {
        $kana = self::katakanaToHiragana($text);
        $chars = mb_str_split($kana);
        $count = count($chars);
        $out = '';
        $lastVowel = '';

        for ($i = 0; $i < $count; $i++) {
            $char = $chars[$i];
            $next = $chars[$i + 1] ?? '';

            // Palatalised combination (two kana).
            $pair = $char.$next;
            if (isset(self::COMBOS[$pair])) {
                $romaji = self::COMBOS[$pair];
                $out .= $romaji;
                $lastVowel = substr($romaji, -1);
                $i++;

                continue;
            }

            // Small tsu doubles the next consonant.
            if ($char === 'っ' || $char === 'ッ') {
                $nextRomaji = self::COMBOS[$next.($chars[$i + 2] ?? '')] ?? self::SINGLES[$next] ?? '';
                if ($nextRomaji !== '') {
                    $out .= $nextRomaji[0];
                }

                continue;
            }

            // Long-vowel mark repeats the previous vowel.
            if ($char === 'ー') {
                $out .= $lastVowel;

                continue;
            }

            if (isset(self::SINGLES[$char])) {
                $romaji = self::SINGLES[$char];
                $out .= $romaji;
                $lastVowel = substr($romaji, -1);

                continue;
            }

            // Kanji or any other character: leave untouched.
            $out .= $char;
            $lastVowel = '';
        }

        return $out;
    }

    private static function katakanaToHiragana(string $text): string
    {
        $result = '';
        foreach (mb_str_split($text) as $char) {
            $code = mb_ord($char, 'UTF-8');
            if ($code !== false && $code >= 0x30A1 && $code <= 0x30F6) {
                $result .= mb_chr($code - 0x60, 'UTF-8');

                continue;
            }
            $result .= $char;
        }

        return $result;
    }
}
