<?php

namespace App\Models;

use App\Enums\SongGenre;
use App\Enums\SongPartsSet;
use App\Enums\SongWai2PartsSet;
use Illuminate\Database\Eloquent\Model;

class Song extends Model
{
    protected $fillable = [
        'version',
        'song_no',
        'music_id',
        'unique_id',
        'title',
        'title_en',
        'search_index',
        'genre',
        'partsset',
        'wai2_partsset',
        'flags',
        'tags',
    ];

    protected $casts = [
        'version' => 'string',
        'song_no' => 'integer',
        'music_id' => 'string',
        'unique_id' => 'integer',
        'title' => 'string',
        'title_en' => 'string',
        'genre' => SongGenre::class,
        'partsset' => SongPartsSet::class,
        'wai2_partsset' => SongWai2PartsSet::class,
        'flags' => 'array',
        'tags' => 'array',
    ];

    public function titleClean(): string
    {
        $title = mb_strtolower($this->title);
        // Fullwidth to halfwidth conversion
        $result = '';
        for ($i = 0, $len = mb_strlen($title); $i < $len; $i++) {
            $char = mb_substr($title, $i, 1);
            $ord = mb_ord($char, 'UTF-8');
            if ($ord >= 0xFF01 && $ord <= 0xFF5E) {
                $result .= mb_chr($ord - 0xFEE0, 'UTF-8');
            } else {
                $result .= $char;
            }
        }

        return $result;
    }
}
