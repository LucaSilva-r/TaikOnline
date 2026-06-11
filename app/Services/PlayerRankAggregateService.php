<?php

namespace App\Services;

use App\Enums\TaikoGameVersion;
use App\Models\Player;
use App\Models\SongBest;
use App\Models\SongPlayResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PlayerRankAggregateService
{
    /**
     * @return Collection<int, array{
     *     user_id: int,
     *     rank: int,
     *     total_score: int,
     *     ranked_song_count: int,
     *     played_song_count: int,
     *     crown_counts: array{none: int, clear: int, gold: int, dondaful: int}
     * }>
     */
    public function forVersion(TaikoGameVersion $version): Collection
    {
        $playersTable = (new Player)->getTable();
        $bestsTable = (new SongBest)->getTable();
        $resultsTable = (new SongPlayResult)->getTable();

        $userIds = Player::query()
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->map(fn (mixed $userId): int => (int) $userId)
            ->unique()
            ->values();

        $aggregates = $userIds->mapWithKeys(fn (int $userId): array => [
            $userId => [
                'user_id' => $userId,
                'rank' => 0,
                'total_score' => 0,
                'ranked_song_count' => 0,
                'played_song_count' => 0,
                'crown_counts' => [
                    'none' => 0,
                    'clear' => 0,
                    'gold' => 0,
                    'dondaful' => 0,
                ],
            ],
        ]);

        DB::table($bestsTable)
            ->join($playersTable, "{$playersTable}.baid", '=', "{$bestsTable}.baid")
            ->where("{$bestsTable}.game_version", $version->value)
            ->whereNotNull("{$playersTable}.user_id")
            ->groupBy("{$playersTable}.user_id")
            ->select("{$playersTable}.user_id")
            ->selectRaw("COALESCE(SUM({$bestsTable}.best_score), 0) as total_score")
            ->selectRaw('COUNT(*) as ranked_song_count')
            ->selectRaw("SUM(CASE WHEN {$bestsTable}.best_crown = 0 THEN 1 ELSE 0 END) as none_crowns")
            ->selectRaw("SUM(CASE WHEN {$bestsTable}.best_crown = 1 THEN 1 ELSE 0 END) as clear_crowns")
            ->selectRaw("SUM(CASE WHEN {$bestsTable}.best_crown = 2 THEN 1 ELSE 0 END) as gold_crowns")
            ->selectRaw("SUM(CASE WHEN {$bestsTable}.best_crown = 3 THEN 1 ELSE 0 END) as dondaful_crowns")
            ->get()
            ->each(function (object $row) use ($aggregates): void {
                $userId = (int) $row->user_id;
                if (! $aggregates->has($userId)) {
                    return;
                }

                $aggregate = $aggregates->get($userId);
                $aggregate['total_score'] = (int) $row->total_score;
                $aggregate['ranked_song_count'] = (int) $row->ranked_song_count;
                $aggregate['crown_counts'] = [
                    'none' => (int) $row->none_crowns,
                    'clear' => (int) $row->clear_crowns,
                    'gold' => (int) $row->gold_crowns,
                    'dondaful' => (int) $row->dondaful_crowns,
                ];

                $aggregates->put($userId, $aggregate);
            });

        DB::table($resultsTable)
            ->join($playersTable, "{$playersTable}.baid", '=', "{$resultsTable}.baid")
            ->where("{$resultsTable}.game_version", $version->value)
            ->whereNotNull("{$playersTable}.user_id")
            ->groupBy("{$playersTable}.user_id")
            ->select("{$playersTable}.user_id")
            ->selectRaw("COUNT(DISTINCT {$resultsTable}.song_no) as played_song_count")
            ->get()
            ->each(function (object $row) use ($aggregates): void {
                $userId = (int) $row->user_id;
                if (! $aggregates->has($userId)) {
                    return;
                }

                $aggregate = $aggregates->get($userId);
                $aggregate['played_song_count'] = (int) $row->played_song_count;

                $aggregates->put($userId, $aggregate);
            });

        return $aggregates
            ->sort(function (array $left, array $right): int {
                return [$right['total_score'], $right['ranked_song_count'], $left['user_id']]
                    <=> [$left['total_score'], $left['ranked_song_count'], $right['user_id']];
            })
            ->values()
            ->mapWithKeys(function (array $aggregate, int $index): array {
                $aggregate['rank'] = $index + 1;

                return [$aggregate['user_id'] => $aggregate];
            });
    }
}
