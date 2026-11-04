<?php

namespace App\Services;

use App\Models\Player;
use App\Models\PlayerCosmetic;
use App\Models\WdbDanCourse;
use App\Models\WdbDanPlay;

class WaddamburoDanRank
{
    /** @return array{series: string, label: string, rank: int, gold: bool}|null */
    public function selected(Player $player, ?PlayerCosmetic $cosmetic = null): ?array
    {
        $cosmetic ??= PlayerCosmetic::resolve($player->baid, PlayerCosmetic::WADDAMBURO);

        return $cosmetic->wdb_dan_series === null ? null : $this->highest($player, $cosmetic->wdb_dan_series);
    }

    /**
     * The highest current course cleared in this series, using only server-verified, visible results.
     *
     * @return array{series: string, label: string, rank: int, gold: bool}|null
     */
    public function highest(Player $player, string $series): ?array
    {
        if (! array_key_exists($series, WdbDanCourse::SERIES) || in_array($series, WdbDanCourse::disabledSeries(), true)) {
            return null;
        }

        $courses = WdbDanCourse::query()->where('series', $series)->orderByDesc('level')->get(['series', 'name', 'content_hash']);
        $bests = WdbDanPlay::query()->where('baid', $player->baid)->whereIn('content_hash', $courses->pluck('content_hash'))
            ->whereNotNull('rescored_at')->whereNull('hidden_at')->groupBy('content_hash')
            ->selectRaw('content_hash, MAX(result_level) AS best')->pluck('best', 'content_hash');
        foreach ($courses as $course) {
            $rank = $course->nameplateRank();
            $best = (int) ($bests[$course->content_hash] ?? 0);
            if ($rank > 0 && $best >= WdbDanPlay::RED) {
                return [
                    'series' => $series,
                    'label' => WdbDanCourse::RANK_LABELS[$course->name],
                    'rank' => $rank,
                    'gold' => $best === WdbDanPlay::GOLD,
                ];
            }
        }

        return null;
    }
}
