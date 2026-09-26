<?php

namespace App\Http\Controllers;

use App\Enums\TaikoGameVersion;
use App\Models\PlayerRankSnapshot;
use App\Models\User;
use App\Services\ExtraRankAggregateService;
use App\Services\PlayerRankAggregateService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class RankingController extends Controller
{
    /**
     * Number of players shown on the global leaderboard.
     */
    private const LIMIT = 100;

    public function index(Request $request, PlayerRankAggregateService $rankAggregates, ExtraWebController $extra): Response
    {
        if ((bool) $request->attributes->get('taikoVersionIsExtra', false)) {
            return $extra->rankings(app(ExtraRankAggregateService::class));
        }
        $version = $request->attributes->get('taikoGameVersion');
        if (! $version instanceof TaikoGameVersion || (bool) $request->attributes->get('taikoVersionIsAll', false)) {
            abort(404);
        }

        $aggregates = $rankAggregates->forVersion($version)
            ->filter(fn (array $aggregate): bool => $aggregate['ranked_song_count'] > 0)
            ->take(self::LIMIT)
            ->values();

        $userIds = $aggregates->pluck('user_id')->all();

        $users = User::query()
            ->whereIn('id', $userIds)
            ->get()
            ->keyBy('id');

        $previousRanks = $this->previousRanks($version, $userIds);

        $entries = $aggregates->map(function (array $aggregate) use ($users, $previousRanks): array {
            $user = $users->get($aggregate['user_id']);
            $previousRank = $previousRanks->get($aggregate['user_id']);

            return [
                'rank' => $aggregate['rank'],
                'rank_change' => $previousRank !== null ? $previousRank - $aggregate['rank'] : null,
                'user_id' => $aggregate['user_id'],
                'player_name' => $user?->name ?? 'Unknown',
                'avatar' => $user?->avatar,
                'total_score' => $aggregate['total_score'],
                'ranked_song_count' => $aggregate['ranked_song_count'],
                'precision' => $aggregate['precision'],
                'crown_counts' => $aggregate['crown_counts'],
            ];
        })->all();

        return Inertia::render('Rankings', [
            'gameVersion' => [
                'value' => $version->value,
                'label' => $version->label(),
            ],
            'entries' => $entries,
        ]);
    }

    /**
     * Rank each user held at the most recent snapshot taken before today, used
     * to compute the up/down movement against the current live standings.
     *
     * @param  array<int, int>  $userIds
     * @return Collection<int, int>
     */
    private function previousRanks(TaikoGameVersion $version, array $userIds): Collection
    {
        $previousDate = PlayerRankSnapshot::query()
            ->where('game_version', $version->value)
            ->whereDate('snapshot_date', '<', today())
            ->max('snapshot_date');

        if ($previousDate === null) {
            return collect();
        }

        return PlayerRankSnapshot::query()
            ->where('game_version', $version->value)
            ->whereDate('snapshot_date', Carbon::parse($previousDate)->toDateString())
            ->whereIn('user_id', $userIds)
            ->pluck('rank', 'user_id')
            ->map(fn (mixed $rank): int => (int) $rank);
    }
}
