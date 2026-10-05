<?php

namespace App\Models;

use App\Casts\PostgresBytea;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Waddamburo chart: its canonical parsed notes (gzipped, never served back) and display
 * metadata. Unranked until an admin sets ranked_at. difficulty is the chart's own name where
 * courses are not named (osu!); the osu ids link it on osu.ppy.sh.
 */
#[Fillable(['sha256', 'notes', 'title', 'subtitle', 'title_en', 'subtitle_en', 'source', 'course', 'level', 'difficulty',
    'osu_beatmap_id', 'osu_beatmapset_id', 'ranked_at'])]
#[Hidden(['notes'])]
class WdbChart extends Model
{
    protected function casts(): array
    {
        return [
            'notes' => PostgresBytea::class,
            'ranked_at' => 'datetime',
        ];
    }

    /** The chart's page on osu.ppy.sh (the set's page when only the set is known). */
    public function osuUrl(): ?string
    {
        return match (true) {
            $this->osu_beatmapset_id !== null && $this->osu_beatmap_id !== null => "https://osu.ppy.sh/beatmapsets/{$this->osu_beatmapset_id}#taiko/{$this->osu_beatmap_id}",
            $this->osu_beatmapset_id !== null => "https://osu.ppy.sh/beatmapsets/{$this->osu_beatmapset_id}",
            $this->osu_beatmap_id !== null => "https://osu.ppy.sh/beatmaps/{$this->osu_beatmap_id}",
            default => null,
        };
    }

    /** The songs this chart is a course of (once per source that ships it in the song). */
    public function songs(): BelongsToMany
    {
        return $this->belongsToMany(WdbSong::class, 'wdb_chart_song')->withPivot('source');
    }

    public function plays(): HasMany
    {
        return $this->hasMany(WdbPlay::class);
    }
}
