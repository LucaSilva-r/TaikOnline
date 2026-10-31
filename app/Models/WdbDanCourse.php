<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A dan dojo course of one series. requirements is a list of {type, red, gold}: red and gold hold one value
 * for the whole course, or one per song. 0 = no such requirement at that level or for that song (Murasaki's
 * 六段 asks hits for red but score for gold). bads and oks must stay under their value, the rest reach it.
 * content_hash changes with the songs or the requirements, so a pass never carries over to a changed course.
 */
#[Fillable(['series', 'level', 'name', 'requirements', 'content_hash'])]
class WdbDanCourse extends Model
{
    /** The series in the order players see them, newest first: id => name. */
    /** The ladder's ranks in every series, in romaji; any other course is a challenge. */
    public const RANK_LABELS = [
        '初級' => 'Beginner',
        '十級' => '10 Kyu', '九級' => '9 Kyu', '八級' => '8 Kyu', '七級' => '7 Kyu', '六級' => '6 Kyu',
        '五級' => '5 Kyu', '四級' => '4 Kyu', '三級' => '3 Kyu', '二級' => '2 Kyu', '一級' => '1 Kyu',
        '４級' => '4 Kyu', '３級' => '3 Kyu', '２級' => '2 Kyu', '１級' => '1 Kyu',
        '初段' => '1 Dan', '二段' => '2 Dan', '三段' => '3 Dan', '四段' => '4 Dan', '五段' => '5 Dan',
        '六段' => '6 Dan', '七段' => '7 Dan', '八段' => '8 Dan', '九段' => '9 Dan', '十段' => '10 Dan',
        '玄人' => 'Kurouto', '名人' => 'Meijin', '超人' => 'Chojin', '達人' => 'Tatsujin',
        '先鋒' => 'Senpo', '次鋒' => 'Jiho', '中堅' => 'Chuken', '副将' => 'Fukusho', '大将' => 'Taisho',
    ];

    public const SERIES = [
        'green' => 'Green',
        'blue' => 'Blue',
        'yellow' => 'Yellow',
        'red' => 'Red',
        'white' => 'White',
        'murasaki' => 'Murasaki',
        'kimidori' => 'Kimidori',
        'momoiro' => 'Momoiro',
        'sorairo' => 'Sorairo',
        'katsudon' => 'KATSU-DON',
    ];

    protected function casts(): array
    {
        return [
            'requirements' => 'array',
        ];
    }

    public function songs(): HasMany
    {
        return $this->hasMany(WdbDanCourseSong::class)->orderBy('position');
    }
}
