<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\PlayerRankSnapshot;
use App\Models\PlayerVersionStats;
use App\Models\User;
use App\Models\WdbChart;
use App\Models\WdbPlay;
use App\Services\PlayerRankAggregateService;
use App\Services\WaddamburoRankAggregateService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Waddamburo scope of the site: the same pages as a game version (rankings, songs, song page,
 * board), fed from Waddamburo's own plays. A "song" is the charts sharing a title, subtitle and
 * source (one per course); its id is its lowest chart id. Leaderboards show every chart; only
 * ranked charts count towards the rankings.
 */
class WaddamburoWebController extends Controller
{
    public function rankings(WaddamburoRankAggregateService $aggregates): Response
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
        $songs = DB::table('wdb_charts')
            ->whereNotNull('title')
            ->whereExists(fn (QueryBuilder $query) => $query->from('wdb_plays')->whereColumn('wdb_plays.wdb_chart_id', 'wdb_charts.id'))
            ->when($search !== '', fn (QueryBuilder $query) => $query->where(fn (QueryBuilder $inner) => $inner
                ->where('title', 'ilike', "%{$search}%")->orWhere('subtitle', 'ilike', "%{$search}%")))
            ->groupBy('title', 'subtitle', 'source')
            ->select('title', 'subtitle', 'source')
            ->selectRaw('MIN(id) AS id, MAX(created_at) AS added_at')
            ->orderByDesc('added_at')
            ->paginate(40)
            ->withQueryString()
            ->through(function (object $song): array {
                $plays = WdbPlay::query()->whereIn('wdb_chart_id', $this->chartsOf($song)->pluck('id'));

                return [
                    'id' => (int) $song->id,
                    'song_no' => (int) $song->id,
                    'title' => (string) $song->title,
                    'title_en' => $song->subtitle,
                    'genre' => ['value' => 'waddamburo', 'label' => $song->source ?? 'Waddamburo'],
                    'play_count' => (clone $plays)->count(),
                    'player_count' => (clone $plays)->distinct()->count('baid'),
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
        $first = WdbChart::query()->whereNotNull('title')->findOrFail($id);
        $charts = $this->chartsOf($first);
        $chartIds = $charts->pluck('id');
        $plays = WdbPlay::query()->whereIn('wdb_chart_id', $chartIds);

        return Inertia::render('SongDetail', [
            'gameVersion' => $this->scope(),
            'song' => [
                'id' => (int) $charts->min('id'),
                'song_no' => (int) $charts->min('id'),
                'title' => $first->title,
                'title_en' => $first->subtitle,
                'genre' => ['value' => 'waddamburo', 'label' => $first->source ?? 'Waddamburo', 'label_jp' => $first->source ?? 'Waddamburo'],
            ],
            'summary' => [
                'total_plays' => (clone $plays)->count(),
                'unique_players' => (clone $plays)->distinct()->count('baid'),
                'first_played_at' => (clone $plays)->min('played_at'),
                'last_played_at' => (clone $plays)->max('played_at'),
            ],
            'difficulties' => $charts->sortBy('course')->map(fn (WdbChart $chart): array => $this->difficultyBoard($chart))->values()->all(),
            'recentPlays' => $this->songRecentPlays($chartIds),
            'isFavorite' => false,
            'favoritesSupported' => false,
            'canFavorite' => false,
            'favoriteLimit' => 0,
            'favoriteCount' => 0,
        ]);
    }

    public function board(Request $request, User $user, WaddamburoRankAggregateService $aggregates): Response
    {
        $user->load('player');
        $player = $user->player;
        $canSeeUnranked = $request->user()?->id === $user->id || $request->user()?->isAdmin() === true;
        $standings = $aggregates->standings();
        $summary = $player instanceof Player ? $standings->firstWhere('user_id', $user->id) : null;

        return Inertia::render('Board', [
            'profile' => [
                'id' => $user->id, 'name' => $user->name, 'avatar' => $user->avatar,
                'mydon_name' => $player?->mydon_name, 'game_version' => $this->scope(),
                'last_played_at' => $player instanceof Player ? WdbPlay::query()->where('baid', $player->baid)->max('played_at') : null,
                'total_credit_count' => 0,
                'don_medals' => ['earned' => 0, 'spent' => 0],
                'katsu_medals' => ['earned' => 0, 'spent' => 0],
            ],
            'hasPlayer' => $player instanceof Player,
            'summary' => $summary ? [
                'rank' => $standings->search(fn (PlayerVersionStats $row) => $row->user_id === $user->id) + 1,
                'total_score' => (int) $summary->total_score,
                'ranked_song_count' => (int) $summary->ranked_song_count,
                'played_song_count' => (int) $summary->played_song_count,
                'precision' => (float) $summary->precision,
                'crown_counts' => $this->crowns($summary),
            ] : $this->emptySummary(),
            'rankHistory' => PlayerRankSnapshot::query()->where('user_id', $user->id)->where('game_version', WaddamburoRankAggregateService::SCOPE)->latest('snapshot_date')->limit(90)->get()->sortBy('snapshot_date')->map(fn ($row): array => ['date' => $row->snapshot_date->toDateString(), 'rank' => (int) $row->rank, 'total_score' => (int) $row->total_score])->values()->all(),
            'recentPlays' => $player instanceof Player ? $this->playerRecentPlays($player, $canSeeUnranked) : [],
            'bestPerformances' => $player instanceof Player ? $this->playerBests($player, $canSeeUnranked) : [],
            'blueBattleData' => null, 'greenGhostData' => null, 'tokkunData' => null, 'daniData' => null,
        ]);
    }

    /** @return Collection<int, WdbChart> the charts of the song (title, subtitle, source) a chart belongs to */
    private function chartsOf(object $song): Collection
    {
        return WdbChart::query()
            ->where('title', $song->title)
            ->when($song->subtitle === null, fn (Builder $query) => $query->whereNull('subtitle'), fn (Builder $query) => $query->where('subtitle', $song->subtitle))
            ->when($song->source === null, fn (Builder $query) => $query->whereNull('source'), fn (Builder $query) => $query->where('source', $song->source))
            ->get();
    }

    private function difficultyBoard(WdbChart $chart): array
    {
        $rows = WaddamburoRankAggregateService::bests(fn (QueryBuilder $query) => $query->where('wdb_plays.wdb_chart_id', $chart->id))
            ->orderByDesc('best_score')
            ->get();
        $players = Player::query()->whereIn('baid', $rows->pluck('baid'))->with('user')->get()->keyBy('baid');

        return [
            'level' => (int) $chart->course + 1,
            'play_count' => WdbPlay::query()->where('wdb_chart_id', $chart->id)->count(),
            'player_count' => $rows->count(),
            'crown_counts' => ['clear' => $rows->where('best_crown', 1)->count(), 'gold' => $rows->where('best_crown', 2)->count(), 'dondaful' => $rows->where('best_crown', 3)->count()],
            'entries' => $rows->filter(fn ($row) => $players->get($row->baid)?->user !== null)->take(20)->values()->map(function ($row, int $index) use ($players): array {
                $user = $players->get($row->baid)->user;

                return [
                    'rank' => $index + 1, 'user_id' => (int) $user->id,
                    'player_name' => $user->name, 'avatar' => $user->avatar,
                    'score' => (int) $row->best_score, 'score_rank' => 0,
                    'crown' => (int) $row->best_crown, 'precision' => null,
                ];
            })->all(),
        ];
    }

    private function songRecentPlays(Collection $chartIds): array
    {
        return WdbPlay::query()->whereIn('wdb_chart_id', $chartIds)->with('player.user')->latest('played_at')->limit(15)->get()
            ->filter(fn (WdbPlay $play) => $play->player?->user !== null)
            ->map(fn (WdbPlay $play): array => [
                'user_id' => (int) $play->player->user_id, 'player_name' => $play->player->user->name,
                'avatar' => $play->player->user->avatar, 'level' => (int) $play->course + 1,
                'played_at' => $play->played_at?->toDateTimeString(), 'play_result' => self::crown($play),
                'score' => (int) $play->score, 'score_rank' => 0,
                'precision' => PlayerRankAggregateService::precision((int) $play->great, (int) $play->good, (int) $play->miss),
            ])->values()->all();
    }

    private function playerRecentPlays(Player $player, bool $includeUnranked): array
    {
        return WdbPlay::query()->where('baid', $player->baid)->with('chart')
            ->when(! $includeUnranked, fn (Builder $query) => $query->whereHas('chart', fn (Builder $chart) => $chart->whereNotNull('ranked_at')))
            ->latest('played_at')->limit(10)->get()
            ->map(fn (WdbPlay $play): array => [
                'song_title' => $play->chart->title ?? 'Unknown chart',
                'song_id' => $play->chart->title === null ? null : $this->songId($play->chart), 'song_no' => (int) $play->wdb_chart_id,
                'level' => (int) $play->course + 1, 'played_at' => $play->played_at?->toDateTimeString(),
                'play_result' => self::crown($play), 'score' => (int) $play->score,
                'score_rank' => 0, 'good_count' => (int) $play->great,
                'ok_count' => (int) $play->good, 'miss_count' => (int) $play->miss,
                'combo_count' => (int) $play->max_combo,
                'counts_for_leaderboard' => $play->chart->ranked_at !== null,
            ])->all();
    }

    private function playerBests(Player $player, bool $includeUnranked): array
    {
        $rows = WaddamburoRankAggregateService::bests(fn (QueryBuilder $query) => $query
            ->where('wdb_plays.baid', $player->baid)
            ->when(! $includeUnranked, fn (QueryBuilder $ranked) => $ranked->whereNotNull('wdb_charts.ranked_at')))
            ->orderByDesc('best_score')->limit(10)->get();
        $charts = WdbChart::query()->whereIn('id', $rows->pluck('wdb_chart_id'))->get()->keyBy('id');

        return $rows->map(function ($row) use ($charts): array {
            $chart = $charts->get($row->wdb_chart_id);

            return [
                'song_title' => $chart?->title ?? 'Unknown chart',
                'song_id' => $chart?->title === null ? null : $this->songId($chart), 'song_no' => (int) $row->wdb_chart_id,
                'level' => (int) ($chart?->course ?? 0) + 1, 'score' => (int) $row->best_score,
                'score_rank' => 0, 'crown' => (int) $row->best_crown,
                'counts_for_leaderboard' => $chart?->ranked_at !== null,
                'placement' => DB::query()->fromSub(WaddamburoRankAggregateService::bests(fn (QueryBuilder $query) => $query
                    ->where('wdb_plays.wdb_chart_id', $row->wdb_chart_id)), 'bests')
                    ->where('best_score', '>', $row->best_score)->count() + 1,
            ];
        })->all();
    }

    private function songId(WdbChart $chart): int
    {
        return (int) $this->chartsOf($chart)->min('id');
    }

    private static function crown(WdbPlay $play): int
    {
        return match (true) {
            $play->cleared && $play->miss === 0 && $play->good === 0 => 3,
            $play->cleared && $play->miss === 0 => 2,
            $play->cleared => 1,
            default => 0,
        };
    }

    private function scope(): array
    {
        return ['value' => WaddamburoRankAggregateService::SCOPE, 'label' => 'Waddamburo'];
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
