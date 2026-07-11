<?php

namespace App\Services;

use App\Models\ExtraChartBest;
use App\Models\ExtraChartPlayResult;
use App\Models\Player;
use App\Models\PlayerVersionStats;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ExtraRankAggregateService
{
    public const SCOPE = 'extra';

    public function recompute(Player $player): void
    {
        $bests = DB::table((new ExtraChartBest)->getTable())
            ->join('extra_charts', 'extra_charts.id', '=', 'extra_chart_bests.extra_chart_id')
            ->join('extra_songs', 'extra_songs.id', '=', 'extra_charts.extra_song_id')
            ->where('extra_chart_bests.baid', $player->baid)
            ->where('extra_chart_bests.is_shin', false)
            ->where('extra_songs.is_ranked', true)
            ->selectRaw('COALESCE(SUM(best_score), 0) as total_score')
            ->selectRaw('COUNT(*) as ranked_song_count')
            ->selectRaw('SUM(CASE WHEN best_crown = 0 THEN 1 ELSE 0 END) as none_crowns')
            ->selectRaw('SUM(CASE WHEN best_crown = 1 THEN 1 ELSE 0 END) as clear_crowns')
            ->selectRaw('SUM(CASE WHEN best_crown = 2 THEN 1 ELSE 0 END) as gold_crowns')
            ->selectRaw('SUM(CASE WHEN best_crown = 3 THEN 1 ELSE 0 END) as dondaful_crowns')
            ->first();

        $plays = DB::table((new ExtraChartPlayResult)->getTable())
            ->join('extra_charts', 'extra_charts.id', '=', 'extra_chart_play_results.extra_chart_id')
            ->join('extra_songs', 'extra_songs.id', '=', 'extra_charts.extra_song_id')
            ->where('extra_chart_play_results.baid', $player->baid)
            ->where('extra_songs.is_ranked', true)
            ->selectRaw('COUNT(DISTINCT extra_chart_play_results.extra_chart_id) as played_song_count')
            ->selectRaw('COALESCE(SUM(good_count), 0) as good_total')
            ->selectRaw('COALESCE(SUM(ok_count), 0) as ok_total')
            ->selectRaw('COALESCE(SUM(miss_count), 0) as miss_total')
            ->first();

        $good = (int) ($plays->good_total ?? 0);
        $ok = (int) ($plays->ok_total ?? 0);
        $miss = (int) ($plays->miss_total ?? 0);
        PlayerVersionStats::query()->updateOrCreate(
            ['baid' => $player->baid, 'game_version' => self::SCOPE],
            [
                'user_id' => $player->user_id,
                'total_score' => (int) ($bests->total_score ?? 0),
                'ranked_song_count' => (int) ($bests->ranked_song_count ?? 0),
                'played_song_count' => (int) ($plays->played_song_count ?? 0),
                'crown_none' => (int) ($bests->none_crowns ?? 0),
                'crown_clear' => (int) ($bests->clear_crowns ?? 0),
                'crown_gold' => (int) ($bests->gold_crowns ?? 0),
                'crown_dondaful' => (int) ($bests->dondaful_crowns ?? 0),
                'good_total' => $good,
                'ok_total' => $ok,
                'miss_total' => $miss,
                'precision' => PlayerRankAggregateService::precision($good, $ok, $miss),
            ],
        );
    }

    /** @return Collection<int, PlayerVersionStats> */
    public function standings(): Collection
    {
        return PlayerVersionStats::query()
            ->where('game_version', self::SCOPE)
            ->whereNotNull('user_id')
            ->where('ranked_song_count', '>', 0)
            ->orderByDesc('total_score')
            ->orderByDesc('ranked_song_count')
            ->orderBy('user_id')
            ->get();
    }
}
