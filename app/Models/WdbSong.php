<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A Waddamburo song: a family's song id (see WaddamburoSongs), display titles, and its charts. A
 * chart shipped by two sources (stock and Nijiiro) is linked once per source (pivot.source).
 */
class WdbSong extends Model
{
    public function charts(): BelongsToMany
    {
        return $this->belongsToMany(WdbChart::class, 'wdb_chart_song')->withPivot('source');
    }

    /** The song's beatmap set on osu.ppy.sh, for osu! songs. */
    public function osuUrl(): ?string
    {
        return $this->osu_beatmapset_id === null ? null : "https://osu.ppy.sh/beatmapsets/{$this->osu_beatmapset_id}";
    }
}
