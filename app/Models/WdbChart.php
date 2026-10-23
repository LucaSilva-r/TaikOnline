<?php

namespace App\Models;

use App\Casts\PostgresBytea;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A Waddamburo chart: its canonical parsed notes (gzipped, never served back) and display
 * metadata. is_stock marks the official charts (unmodified Green and Nijiiro, and songs the Nijiiro
 * cabinet had at some point; see ImportWaddamburoChartsCommand), which are ranked; any other chart is unranked until an admin sets ranked_at. difficulty is the chart's
 * own name where courses are not named (osu!); the osu ids link it on osu.ppy.sh.
 */
#[Fillable(['sha256', 'notes', 'title', 'subtitle', 'title_en', 'subtitle_en', 'source', 'is_stock', 'course', 'level', 'roll_max', 'difficulty',
    'osu_beatmap_id', 'osu_beatmapset_id', 'ranked_at'])]
#[Hidden(['notes'])]
class WdbChart extends Model
{
    protected function casts(): array
    {
        return [
            'notes' => PostgresBytea::class,
            'is_stock' => 'boolean',
            'ranked_at' => 'datetime',
        ];
    }

    /**
     * The ids of the charts the website shows a viewer: ranked charts to everyone, and the unranked
     * (custom) ones only to players who played them, with everyone's scores on them; admins see every
     * played chart. Custom charts belong to their communities, so nobody browses ones they never played.
     */
    public static function visibleTo(?User $viewer): Builder
    {
        $baid = $viewer?->isAdmin() === true ? null : $viewer?->player?->baid;

        return DB::table('wdb_charts')->select('wdb_charts.id')->where(fn (Builder $visible) => $visible
            ->whereNotNull('wdb_charts.ranked_at')
            ->when($viewer?->isAdmin() === true || $baid !== null, fn (Builder $played) => $played
                ->orWhereExists(fn (Builder $plays) => $plays->from('wdb_plays')
                    ->whereColumn('wdb_plays.wdb_chart_id', 'wdb_charts.id')
                    ->when($baid !== null, fn (Builder $own) => $own->where('wdb_plays.baid', $baid)))));
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
