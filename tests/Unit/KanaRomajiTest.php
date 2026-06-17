<?php

use App\Support\KanaRomaji;
use App\Support\SongSearch;

it('transliterates hiragana and katakana to romaji', function (string $kana, string $expected): void {
    expect(KanaRomaji::toRomaji($kana))->toBe($expected);
})->with([
    ['にんじゃ', 'ninja'],
    ['とらんど', 'torando'],
    ['きょう', 'kyou'],
    ['がっこう', 'gakkou'],
    ['ニンジャ', 'ninja'],
    ['ラーメン', 'raamen'],
]);

it('leaves kanji untouched while romanising surrounding kana', function (): void {
    expect(KanaRomaji::toRomaji('女々しくて'))->toBe('女々shikute');
});

it('normalises fullwidth latin and lowercases', function (): void {
    expect(SongSearch::normalize('Ｄａｎｃｅ Ｍｙ'))->toBe('dance my');
});
