<?php

namespace App\Http\Controllers;

use App\Models\ExtraChartBest;
use App\Models\ExtraChartPlayResult;
use App\Models\ExtraSong;
use App\Models\Player;
use App\Models\PlayerRankSnapshot;
use App\Models\PlayerVersionStats;
use App\Models\User;
use App\Services\ExtraRankAggregateService;
use App\Services\PlayerRankAggregateService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class ExtraWebController extends Controller
{
    public function rankings(ExtraRankAggregateService $aggregates): Response
    {
        $stats = $aggregates->standings()->take(100)->values();
        $users = User::query()->whereIn('id', $stats->pluck('user_id'))->get()->keyBy('id');

        return Inertia::render('Rankings', [
            'gameVersion' => $this->scope(),
            'entries' => $stats->map(function (PlayerVersionStats $row, int $index) use ($users): array {
                $user = $users->get($row->user_id);

                return [
                    'rank' => $index + 1,
                    'rank_change' => null,
                    'user_id' => (int) $row->user_id,
                    'player_name' => $user?->name ?? 'Unknown',
                    'avatar' => $user?->avatar,
                    'total_score' => (int) $row->total_score,
                    'ranked_song_count' => (int) $row->ranked_song_count,
                    'precision' => (float) $row->precision,
                    'crown_counts' => $this->crowns($row),
                ];
            })->all(),
        ]);
    }

    public function songs(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $songs = ExtraSong::query()
            ->where('is_ranked', true)
            ->with('charts:id,extra_song_id,difficulty')
            ->when($search !== '', fn (Builder $query) => $query->where('title', 'like', "%{$search}%"))
            ->latest()
            ->paginate(40)
            ->withQueryString()
            ->through(function (ExtraSong $song): array {
                $chartIds = $song->charts->pluck('id');

                return [
                    'id' => $song->id,
                    'song_no' => $song->id,
                    'title' => $song->title.($song->edition ? " ({$song->edition})" : ''),
                    'title_en' => $song->subtitle,
                    'genre' => ['value' => 'extra', 'label' => 'Extra'],
                    'play_count' => ExtraChartPlayResult::query()->whereIn('extra_chart_id', $chartIds)->count(),
                    'player_count' => ExtraChartPlayResult::query()->whereIn('extra_chart_id', $chartIds)->distinct()->count('baid'),
                    'is_favorite' => false,
                ];
            });

        return Inertia::render('Songs', [
            'gameVersion' => $this->scope(),
            'songs' => $songs,
            'filters' => ['q' => $search],
            'favoritesSupported' => false,
            'canFavorite' => false,
            'favoriteLimit' => 0,
            'favoriteCount' => 0,
        ]);
    }

    public function song(string $id): Response
    {
        $song = ExtraSong::query()->where('is_ranked', true)->with('charts')->findOrFail($id);
        $chartIds = $song->charts->pluck('id');
        $results = ExtraChartPlayResult::query()->whereIn('extra_chart_id', $chartIds);

        return Inertia::render('SongDetail', [
            'gameVersion' => $this->scope(),
            'song' => [
                'id' => $song->id,
                'song_no' => $song->id,
                'title' => $song->title,
                'title_en' => $song->subtitle,
                'genre' => ['value' => 'extra', 'label' => 'Extra', 'label_jp' => 'Extra'],
            ],
            'summary' => [
                'total_plays' => (clone $results)->count(),
                'unique_players' => (clone $results)->distinct()->count('baid'),
                'first_played_at' => (clone $results)->min('played_at'),
                'last_played_at' => (clone $results)->max('played_at'),
            ],
            'difficulties' => $this->difficultyBoards($song),
            'recentPlays' => $this->songRecentPlays($chartIds),
            'isFavorite' => false,
            'favoritesSupported' => false,
            'canFavorite' => false,
            'favoriteLimit' => 0,
            'favoriteCount' => 0,
        ]);
    }

    public function board(Request $request, User $user, ExtraRankAggregateService $aggregates): Response
    {
        $user->load('player');
        $player = $user->player;
        $canSeeUnregistered = $request->user()?->id === $user->id || $request->user()?->isAdmin() === true;
        $summary = $player instanceof Player
            ? $aggregates->standings()->firstWhere('user_id', $user->id)
            : null;

        return Inertia::render('Board', [
            'profile' => [
                'id' => $user->id, 'name' => $user->name, 'avatar' => $user->avatar,
                'mydon_name' => $player?->mydon_name, 'game_version' => $this->scope(),
                'last_played_at' => $player?->last_played_at?->toDateTimeString(),
                'total_credit_count' => (int) ($player?->total_credit_count ?? 0),
                'don_medals' => ['earned' => 0, 'spent' => 0],
                'katsu_medals' => ['earned' => 0, 'spent' => 0],
            ],
            'hasPlayer' => $player instanceof Player,
            'summary' => $summary ? [
                'rank' => $aggregates->standings()->search(fn (PlayerVersionStats $row) => $row->user_id === $user->id) + 1,
                'total_score' => (int) $summary->total_score,
                'ranked_song_count' => (int) $summary->ranked_song_count,
                'played_song_count' => (int) $summary->played_song_count,
                'precision' => (float) $summary->precision,
                'crown_counts' => $this->crowns($summary),
            ] : $this->emptySummary(),
            'rankHistory' => PlayerRankSnapshot::query()->where('user_id', $user->id)->where('game_version', 'extra')->latest('snapshot_date')->limit(90)->get()->sortBy('snapshot_date')->map(fn ($row): array => ['date' => $row->snapshot_date->toDateString(), 'rank' => (int) $row->rank, 'total_score' => (int) $row->total_score])->values()->all(),
            'recentPlays' => $player instanceof Player ? $this->playerRecentPlays($player, $canSeeUnregistered) : [],
            'bestPerformances' => $player instanceof Player ? $this->playerBests($player, $canSeeUnregistered) : [],
            'blueBattleData' => null, 'greenGhostData' => null, 'tokkunData' => null, 'daniData' => null,
        ]);
    }

    private function difficultyBoards(ExtraSong $song): array
    {
        return $song->charts->map(function ($chart): array {
            $rows = ExtraChartBest::query()->where('extra_chart_id', $chart->id)->where('is_shin', false)->with('player.user')->orderByDesc('best_score')->get();
            $plays = ExtraChartPlayResult::query()->where('extra_chart_id', $chart->id);

            return [
                'level' => (int) $chart->difficulty,
                'play_count' => (clone $plays)->count(),
                'player_count' => $rows->count(),
                'crown_counts' => ['clear' => $rows->where('best_crown', 1)->count(), 'gold' => $rows->where('best_crown', 2)->count(), 'dondaful' => $rows->where('best_crown', 3)->count()],
                'entries' => $rows->filter(fn ($row) => $row->player?->user !== null)->take(20)->values()->map(fn ($row, int $index): array => [
                    'rank' => $index + 1, 'user_id' => (int) $row->player->user_id,
                    'player_name' => $row->player->user->name, 'avatar' => $row->player->user->avatar,
                    'score' => (int) $row->best_score, 'score_rank' => (int) $row->best_score_rank,
                    'crown' => (int) $row->best_crown, 'precision' => null,
                ])->all(),
            ];
        })->values()->all();
    }

    private function songRecentPlays(Collection $chartIds): array
    {
        return ExtraChartPlayResult::query()->whereIn('extra_chart_id', $chartIds)->with('player.user')->latest('played_at')->limit(15)->get()->filter(fn ($row) => $row->player?->user !== null)->map(fn ($row): array => [
            'user_id' => (int) $row->player->user_id, 'player_name' => $row->player->user->name,
            'avatar' => $row->player->user->avatar, 'level' => (int) $row->level,
            'played_at' => $row->played_at?->toDateTimeString(), 'play_result' => (int) $row->play_result,
            'score' => (int) $row->score, 'score_rank' => (int) $row->score_rank,
            'precision' => PlayerRankAggregateService::precision((int) $row->good_count, (int) $row->ok_count, (int) $row->miss_count),
        ])->values()->all();
    }

    private function playerRecentPlays(Player $player, bool $includeUnregistered): array
    {
        return ExtraChartPlayResult::query()->where('baid', $player->baid)->with('chart.song')->when(! $includeUnregistered, fn ($query) => $query->whereHas('chart.song', fn ($q) => $q->where('is_ranked', true)))->latest('played_at')->limit(10)->get()->map(fn ($row): array => [
            'song_title' => $row->chart->song?->title ?? $row->chart->observed_title ?? 'Unregistered chart',
            'song_id' => $row->chart->song?->id, 'song_no' => (int) $row->extra_chart_id,
            'level' => (int) $row->level, 'played_at' => $row->played_at?->toDateTimeString(),
            'play_result' => (int) $row->play_result, 'score' => (int) $row->score,
            'score_rank' => (int) $row->score_rank, 'good_count' => (int) $row->good_count,
            'ok_count' => (int) $row->ok_count, 'miss_count' => (int) $row->miss_count,
            'combo_count' => (int) $row->combo_count,
            'counts_for_leaderboard' => $row->chart->song?->is_ranked === true,
        ])->all();
    }

    private function playerBests(Player $player, bool $includeUnregistered): array
    {
        return ExtraChartBest::query()->where('baid', $player->baid)->where('is_shin', false)->with('chart.song')->when(! $includeUnregistered, fn ($query) => $query->whereHas('chart.song', fn ($q) => $q->where('is_ranked', true)))->orderByDesc('best_score')->limit(10)->get()->map(fn ($row): array => [
            'song_title' => $row->chart->song?->title ?? $row->chart->observed_title ?? 'Unregistered chart',
            'song_id' => $row->chart->song?->id, 'song_no' => (int) $row->extra_chart_id,
            'level' => (int) ($row->chart->difficulty ?? 0), 'score' => (int) $row->best_score,
            'score_rank' => (int) $row->best_score_rank, 'crown' => (int) $row->best_crown,
            'counts_for_leaderboard' => $row->chart->song?->is_ranked === true,
            'placement' => ExtraChartBest::query()
                ->where('extra_chart_id', $row->extra_chart_id)
                ->where('is_shin', false)
                ->where('best_score', '>', $row->best_score)
                ->count() + 1,
        ])->all();
    }

    private function scope(): array
    {
        return ['value' => 'extra', 'label' => 'EXTRA'];
    }

    private function crowns(PlayerVersionStats $row): array
    {
        return ['none' => (int) $row->crown_none, 'clear' => (int) $row->crown_clear, 'gold' => (int) $row->crown_gold, 'dondaful' => (int) $row->crown_dondaful];
    }

    private function emptySummary(): array
    {
        return ['rank' => null, 'total_score' => 0, 'ranked_song_count' => 0, 'played_song_count' => 0, 'precision' => 0.0, 'crown_counts' => ['none' => 0, 'clear' => 0, 'gold' => 0, 'dondaful' => 0]];
    }
}
