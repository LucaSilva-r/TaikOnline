<?php

namespace App\Services;

use App\Enums\TaikoGameVersion;
use App\Models\Player;
use App\Models\PlayerVersionStats;
use App\Models\SongBest;
use App\Models\SongPlayResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PlayerRankAggregateService
{
    /**
     * Ranked player standings for a version, read from the materialised
     * player_version_stats table (kept fresh by {@see self::recompute()}), so
     * no full aggregation happens on each page request.
     *
     * @return Collection<int, array{
     *     user_id: int,
     *     rank: int,
     *     total_score: int,
     *     ranked_song_count: int,
     *     played_song_count: int,
     *     precision: float,
     *     crown_counts: array{none: int, clear: int, gold: int, dondaful: int}
     * }>
     */
    public function forVersion(TaikoGameVersion $version): Collection
    {
        return PlayerVersionStats::query()
            ->where('game_version', $version->value)
            ->whereNotNull('user_id')
            ->orderByDesc('total_score')
            ->orderByDesc('ranked_song_count')
            ->orderBy('user_id')
            ->get()
            ->values()
            ->mapWithKeys(function (PlayerVersionStats $stats, int $index): array {
                $userId = (int) $stats->user_id;

                return [
                    $userId => [
                        'user_id' => $userId,
                        'rank' => $index + 1,
                        'total_score' => (int) $stats->total_score,
                        'ranked_song_count' => (int) $stats->ranked_song_count,
                        'played_song_count' => (int) $stats->played_song_count,
                        'precision' => (float) $stats->precision,
                        'crown_counts' => [
                            'none' => (int) $stats->crown_none,
                            'clear' => (int) $stats->crown_clear,
                            'gold' => (int) $stats->crown_gold,
                            'dondaful' => (int) $stats->crown_dondaful,
                        ],
                    ],
                ];
            });
    }

    /**
     * Recompute and persist the cached stats for a single player and version.
     * Cheap (indexed by baid) and called whenever that player's scores change.
     */
    public function recompute(Player $player, TaikoGameVersion $version): void
    {
        $bestsTable = (new SongBest)->getTable();
        $resultsTable = (new SongPlayResult)->getTable();

        $bests = DB::table($bestsTable)
            ->where('baid', $player->baid)
            ->where('game_version', $version->value)
            ->where('is_shin', false)
            ->selectRaw('COALESCE(SUM(best_score), 0) as total_score')
            ->selectRaw('COUNT(*) as ranked_song_count')
            ->selectRaw('SUM(CASE WHEN best_crown = 0 THEN 1 ELSE 0 END) as none_crowns')
            ->selectRaw('SUM(CASE WHEN best_crown = 1 THEN 1 ELSE 0 END) as clear_crowns')
            ->selectRaw('SUM(CASE WHEN best_crown = 2 THEN 1 ELSE 0 END) as gold_crowns')
            ->selectRaw('SUM(CASE WHEN best_crown = 3 THEN 1 ELSE 0 END) as dondaful_crowns')
            ->first();

        $plays = DB::table($resultsTable)
            ->where('baid', $player->baid)
            ->where('game_version', $version->value)
            ->selectRaw('COUNT(DISTINCT song_no) as played_song_count')
            ->selectRaw('COALESCE(SUM(good_count), 0) as good_total')
            ->selectRaw('COALESCE(SUM(ok_count), 0) as ok_total')
            ->selectRaw('COALESCE(SUM(miss_count), 0) as miss_total')
            ->first();

        $good = (int) ($plays->good_total ?? 0);
        $ok = (int) ($plays->ok_total ?? 0);
        $miss = (int) ($plays->miss_total ?? 0);

        PlayerVersionStats::query()->updateOrCreate(
            ['baid' => $player->baid, 'game_version' => $version->value],
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
                'precision' => self::precision($good, $ok, $miss),
            ],
        );
    }

    /**
     * Cumulative precision as a 0-100 percentage: good notes score 100%, ok
     * notes 50%, misses 0%. Returns 0 when there are no judged notes.
     */
    public static function precision(int $good, int $ok, int $miss): float
    {
        $total = $good + $ok + $miss;
        if ($total <= 0) {
            return 0.0;
        }

        return round((($good + ($ok * 0.5)) / $total) * 100, 2);
    }
}
