<?php

namespace App\Services;

use App\Models\Player;
use App\Models\PlayerVersionStats;
use App\Models\WdbPlay;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Waddamburo's standings, osu!-style: each player's best score per ranked chart, summed. Unranked
 * charts keep their leaderboards but never count here.
 */
class WaddamburoRankAggregateService
{
    public const SCOPE = 'waddamburo';

    /** SQL crown of one play: 0 none, 1 clear, 2 full combo, 3 dondaful (no goods, no misses). */
    public const CROWN_SQL = 'CASE WHEN wdb_plays.cleared AND wdb_plays.miss = 0 AND wdb_plays.good = 0 THEN 3 '
        .'WHEN wdb_plays.cleared AND wdb_plays.miss = 0 THEN 2 WHEN wdb_plays.cleared THEN 1 ELSE 0 END';

    /** Each (player, chart) best: score and crown, over the given plays. */
    /**
     * Each player's best per chart. Normal plays only, or with $shinuchi only 真打 plays (they score on
     * their own scale, so they rank apart and never count toward the site's totals).
     */
    public static function bests(?callable $filter = null, bool $shinuchi = false): Builder
    {
        return DB::table('wdb_plays')
            ->join('wdb_charts', 'wdb_charts.id', '=', 'wdb_plays.wdb_chart_id')
            ->whereRaw('(wdb_plays.options & ?) '.($shinuchi ? '<>' : '=').' 0', [WdbPlay::SHINUCHI])
            ->when($filter !== null, $filter)
            ->groupBy('wdb_plays.baid', 'wdb_plays.wdb_chart_id')
            ->select('wdb_plays.baid', 'wdb_plays.wdb_chart_id')
            ->selectRaw('MAX(wdb_plays.score) AS best_score')
            ->selectRaw('MAX('.self::CROWN_SQL.') AS best_crown');
    }

    public function recompute(Player $player): void
    {
        $bests = DB::query()
            ->fromSub(self::bests(fn (Builder $query) => $query
                ->where('wdb_plays.baid', $player->baid)
                ->whereNotNull('wdb_charts.ranked_at')), 'bests')
            ->selectRaw('COALESCE(SUM(best_score), 0) AS total_score')
            ->selectRaw('COUNT(*) AS ranked_song_count')
            ->selectRaw('SUM(CASE WHEN best_crown = 0 THEN 1 ELSE 0 END) AS none_crowns')
            ->selectRaw('SUM(CASE WHEN best_crown = 1 THEN 1 ELSE 0 END) AS clear_crowns')
            ->selectRaw('SUM(CASE WHEN best_crown = 2 THEN 1 ELSE 0 END) AS gold_crowns')
            ->selectRaw('SUM(CASE WHEN best_crown = 3 THEN 1 ELSE 0 END) AS dondaful_crowns')
            ->first();

        $plays = DB::table('wdb_plays')
            ->join('wdb_charts', 'wdb_charts.id', '=', 'wdb_plays.wdb_chart_id')
            ->where('wdb_plays.baid', $player->baid)
            ->whereNotNull('wdb_charts.ranked_at')
            ->selectRaw('COUNT(DISTINCT wdb_plays.wdb_chart_id) AS played_song_count')
            ->selectRaw('COALESCE(SUM(great), 0) AS great_total')
            ->selectRaw('COALESCE(SUM(good), 0) AS good_total')
            ->selectRaw('COALESCE(SUM(miss), 0) AS miss_total')
            ->first();

        $great = (int) ($plays->great_total ?? 0);
        $good = (int) ($plays->good_total ?? 0);
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
                'good_total' => $great,
                'ok_total' => $good,
                'miss_total' => $miss,
                'precision' => PlayerRankAggregateService::precision($great, $good, $miss),
            ],
        );
    }

    /** Recomputes everyone who played a chart (after it is ranked or unranked). */
    public function recomputeChart(int $chartId): void
    {
        $baids = DB::table('wdb_plays')->where('wdb_chart_id', $chartId)->distinct()->pluck('baid');
        Player::query()->whereIn('baid', $baids)->get()->each(fn (Player $player) => $this->recompute($player));
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
