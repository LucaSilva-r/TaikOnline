<?php

namespace App\Models;

use App\Casts\PostgresBytea;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One song of a dan play: its chart, counts and replay on the song's own time. */
class WdbDanPlaySong extends Model
{
    protected $guarded = [];

    protected $hidden = ['replay', 'timeline'];

    protected function casts(): array
    {
        return ['replay' => PostgresBytea::class, 'timeline' => PostgresBytea::class];
    }

    /** @return BelongsTo<WdbDanPlay, $this> */
    public function play(): BelongsTo
    {
        return $this->belongsTo(WdbDanPlay::class, 'wdb_dan_play_id');
    }

    /** @return BelongsTo<WdbChart, $this> */
    public function chart(): BelongsTo
    {
        return $this->belongsTo(WdbChart::class, 'wdb_chart_id');
    }
}
