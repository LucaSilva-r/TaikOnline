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
