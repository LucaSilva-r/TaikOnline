<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\PlayerRankSnapshot;
use App\Models\PlayerVersionStats;
use App\Models\User;
use App\Models\WdbChart;
use App\Models\WdbPlay;
use App\Models\WdbSong;
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
 * board), fed from Waddamburo's own plays. A song is a wdb_songs row (its source's song id, see
 * WaddamburoSongs) with its charts, one per course (osu!: one per difficulty). Leaderboards show every chart; only
 * ranked charts count towards the rankings. title is the original (Japanese) title, title_en the
 * English one when the client knows it.
 */
class WaddamburoWebController extends Controller
{
    /** Chart sources as the client names them => label. */
    private const SOURCES = ['Stock' => 'Stock', 'Nijiiro' => 'Nijiiro', 'OsuLazer' => 'osu!lazer', 'Tja' => 'TJA'];

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
        $source = $request->query('source');
        $source = is_string($source) && array_key_exists($source, self::SOURCES) ? $source : null;
        // A chart linked once per source still counts its plays once.
        $plays = fn (string $aggregate): QueryBuilder => DB::table('wdb_plays')
            ->whereIn('wdb_plays.wdb_chart_id', DB::table('wdb_chart_song')->select('wdb_chart_id')
                ->whereColumn('wdb_chart_song.wdb_song_id', 'wdb_songs.id'))
            ->selectRaw($aggregate);
        $songs = WdbSong::query()
            // Ranked songs (the imported library) and anything played.
            ->whereHas('charts', fn (Builder $chart) => $chart->where(fn (Builder $listed) => $listed
                ->whereNotNull('ranked_at')->orWhereHas('plays')))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->whereLike('title', "%{$search}%")->orWhereLike('title_en', "%{$search}%")
                ->orWhereLike('subtitle', "%{$search}%")->orWhereLike('subtitle_en', "%{$search}%")))
            ->when($source !== null, fn (Builder $query) => $query->whereExists(fn (QueryBuilder $link) => $link
                ->from('wdb_chart_song')->whereColumn('wdb_chart_song.wdb_song_id', 'wdb_songs.id')->where('source', $source)))
            ->select('*')
            ->selectSub($plays('COUNT(*)'), 'play_count')
            ->selectSub($plays('COUNT(DISTINCT wdb_plays.baid)'), 'player_count')
            ->orderByDesc('created_at')->orderBy('id')
            ->paginate(40)
            ->withQueryString();
        $sources = DB::table('wdb_chart_song')->whereIn('wdb_song_id', $songs->pluck('id'))->distinct()
            ->get(['wdb_song_id', 'source'])->groupBy('wdb_song_id')->map->pluck('source');
        $songs->through(fn (WdbSong $song): array => [
            'id' => $song->id,
            'song_no' => $song->id,
            'title' => (string) ($song->title ?? $song->title_en),
            'title_en' => $song->title_en,
            'genre' => $this->genre($sources->get($song->id, collect())->all()),
            'play_count' => (int) $song->play_count,
            'player_count' => (int) $song->player_count,
            'is_favorite' => false,
        ]);

        return Inertia::render('Songs', [
            'gameVersion' => $this->scope(),
            'songs' => $songs,
            'filters' => ['q' => $search, 'source' => $source],
            'sources' => collect(self::SOURCES)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all(),
            'favoritesSupported' => false,
            'canFavorite' => false,
            'favoriteLimit' => 0,
            'favoriteCount' => 0,
        ]);
    }

    public function song(string $id): Response
    {
        $song = WdbSong::query()->findOrFail($id);
        // One entry per chart, with every source that ships it in this song.
        $charts = $song->charts->groupBy('id')->map(function (Collection $links): WdbChart {
            $chart = $links->first();
            $chart->setAttribute('sources', self::ordered($links->pluck('pivot.source')->all()));

            return $chart;
        })->values();
        $chartIds = $charts->pluck('id');
        $songSources = self::ordered($charts->pluck('sources')->flatten()->all());
        $plays = WdbPlay::query()->whereIn('wdb_chart_id', $chartIds);

        return Inertia::render('SongDetail', [
            'gameVersion' => $this->scope(),
            'song' => [
                'id' => $song->id,
                'song_no' => $song->id,
                'title' => $song->title ?? $song->title_en,
                'title_en' => $song->title_en,
                'subtitle' => $song->subtitle,
                'subtitle_en' => $song->subtitle_en,
                'genre' => [...$this->genre($songSources), 'label_jp' => $this->genre($songSources)['label']],
                'sources' => array_map(fn (string $source): string => self::SOURCES[$source] ?? $source, $songSources),
                'external_url' => $song->osuUrl(),
            ],
            'summary' => [
                'total_plays' => (clone $plays)->count(),
                'unique_players' => (clone $plays)->distinct()->count('baid'),
                'first_played_at' => (clone $plays)->min('played_at'),
                'last_played_at' => (clone $plays)->max('played_at'),
            ],
            'difficulties' => $charts->sortBy(fn (WdbChart $chart): array => [
                $chart->course, array_search($chart->sources[0] ?? '', array_keys(self::SOURCES), true), $chart->id,
            ])->map(fn (WdbChart $chart): array => $this->difficultyBoard($chart))->values()->all(),
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

    private function difficultyBoard(WdbChart $chart): array
    {
        $rows = WaddamburoRankAggregateService::bests(fn (QueryBuilder $query) => $query->where('wdb_plays.wdb_chart_id', $chart->id))
            ->orderByDesc('best_score')
            ->get();
        $shinuchi = WaddamburoRankAggregateService::bests(fn (QueryBuilder $query) => $query->where('wdb_plays.wdb_chart_id', $chart->id), shinuchi: true)
            ->orderByDesc('best_score')
            ->get();
        $players = Player::query()->whereIn('baid', $rows->pluck('baid')->merge($shinuchi->pluck('baid')))->with('user')->get()->keyBy('baid');
        // Each player's best play itself (the earlier one on a tie, as the game's leaderboard), for its counts.
        $bestPlays = fn (bool $shinuchi): Collection => WdbPlay::query()
            ->fromSub(WdbPlay::query()->select(['baid', 'great', 'good', 'miss', 'max_combo', 'rolls'])
                ->selectRaw('ROW_NUMBER() OVER (PARTITION BY baid ORDER BY score DESC, played_at ASC) AS player_best')
                ->where('wdb_chart_id', $chart->id)->whereNotNull('rescored_at')
                ->whereRaw('(options & ?) '.($shinuchi ? '<>' : '=').' 0', [WdbPlay::SHINUCHI]), 'wdb_plays')
            ->where('player_best', 1)->get()->keyBy('baid');
        $entries = fn (Collection $rows, Collection $plays): array => $rows->filter(fn ($row) => $players->get($row->baid)?->user !== null)->take(20)->values()->map(function ($row, int $index) use ($players, $plays, $chart): array {
            $user = $players->get($row->baid)->user;
            $play = $plays->get($row->baid);

            return [
                'rank' => $index + 1, 'user_id' => (int) $user->id,
                'player_name' => $user->name, 'avatar' => $user->avatar,
                'score' => (int) $row->best_score, 'score_rank' => 0,
                'crown' => (int) $row->best_crown,
                'precision' => $play === null ? null : PlayerRankAggregateService::precision((int) $play->great, (int) $play->good, (int) $play->miss),
                'counts' => $play === null ? null : [
                    'good' => (int) $play->great, 'ok' => (int) $play->good, 'miss' => (int) $play->miss,
                    'combo' => (int) $play->max_combo, 'rolls' => (int) $play->rolls, 'roll_max' => $chart->roll_max,
                ],
            ];
        })->all();

        return [
            'id' => (int) $chart->id,
            'level' => (int) $chart->course + 1,
            'name' => $chart->difficulty,
            'sources' => array_map(fn (string $source): string => self::SOURCES[$source] ?? $source, $chart->sources ?? []),
            'external_url' => $chart->osuUrl(),
            'play_count' => WdbPlay::query()->where('wdb_chart_id', $chart->id)->count(),
            'player_count' => $rows->count(),
            'crown_counts' => ['clear' => $rows->where('best_crown', 1)->count(), 'gold' => $rows->where('best_crown', 2)->count(), 'dondaful' => $rows->where('best_crown', 3)->count()],
            'entries' => $entries($rows, $bestPlays(false)),
            // 真打 plays rank on their own board (another scale).
            'shinuchi_entries' => $entries($shinuchi, $bestPlays(true)),
        ];
    }

    private function songRecentPlays(Collection $chartIds): array
    {
        return WdbPlay::query()->whereIn('wdb_chart_id', $chartIds)->with(['player.user', 'chart'])->latest('played_at')->limit(15)->get()
            ->filter(fn (WdbPlay $play) => $play->player?->user !== null)
            ->map(fn (WdbPlay $play): array => [
                'user_id' => (int) $play->player->user_id, 'player_name' => $play->player->user->name,
                'avatar' => $play->player->user->avatar, 'level' => (int) $play->course + 1,
                'difficulty' => $play->chart?->difficulty,
                'played_at' => $play->played_at?->toDateTimeString(), 'play_result' => self::crown($play),
                'score' => (int) $play->score, 'score_rank' => 0,
                'precision' => PlayerRankAggregateService::precision((int) $play->great, (int) $play->good, (int) $play->miss),
                'options' => WdbPlay::optionLabels((int) $play->options),
            ])->values()->all();
    }

    private function playerRecentPlays(Player $player, bool $includeUnranked): array
    {
        return WdbPlay::query()->where('baid', $player->baid)->with('chart')
            ->when(! $includeUnranked, fn (Builder $query) => $query->whereHas('chart', fn (Builder $chart) => $chart->whereNotNull('ranked_at')))
            ->latest('played_at')->limit(10)->get()
            ->map(fn (WdbPlay $play): array => [
                'song_title' => $play->chart->title ?? 'Unknown chart',
                'song_id' => $this->songId($play->chart), 'song_no' => (int) $play->wdb_chart_id,
                'level' => (int) $play->course + 1, 'difficulty' => $play->chart->difficulty,
                'played_at' => $play->played_at?->toDateTimeString(),
                'play_result' => self::crown($play), 'score' => (int) $play->score,
                'score_rank' => 0, 'good_count' => (int) $play->great,
                'ok_count' => (int) $play->good, 'miss_count' => (int) $play->miss,
                'combo_count' => (int) $play->max_combo,
                // Drumroll hits beside the chart's most (null: not scored by the server yet).
                'roll_count' => (int) $play->rolls, 'roll_max' => $play->chart->roll_max,
                'counts_for_leaderboard' => $play->chart->ranked_at !== null,
                'options' => WdbPlay::optionLabels((int) $play->options),
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
                'song_id' => $chart === null ? null : $this->songId($chart), 'song_no' => (int) $row->wdb_chart_id,
                'level' => (int) ($chart?->course ?? 0) + 1, 'difficulty' => $chart?->difficulty,
                'score' => (int) $row->best_score,
                'score_rank' => 0, 'crown' => (int) $row->best_crown,
                'counts_for_leaderboard' => $chart?->ranked_at !== null,
                'placement' => DB::query()->fromSub(WaddamburoRankAggregateService::bests(fn (QueryBuilder $query) => $query
                    ->where('wdb_plays.wdb_chart_id', $row->wdb_chart_id)), 'bests')
                    ->where('best_score', '>', $row->best_score)->count() + 1,
            ];
        })->all();
    }

    private function songId(WdbChart $chart): ?int
    {
        $id = $chart->songs()->min('wdb_songs.id');

        return $id === null ? null : (int) $id;
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

    /**
     * @param  list<string>  $sources
     * @return array{value: string, label: string} a song's sources as the song pages' genre badge
     */
    private function genre(array $sources): array
    {
        $sources = self::ordered($sources);

        return [
            'value' => 'wdb_'.strtolower(implode('_', $sources) ?: 'unknown'),
            'label' => implode(' · ', array_map(fn (string $source): string => self::SOURCES[$source] ?? $source, $sources)) ?: 'Waddamburo',
        ];
    }

    /**
     * @param  list<string>  $sources
     * @return list<string> the sources once each, in SOURCES order (stock before Nijiiro)
     */
    private static function ordered(array $sources): array
    {
        $sources = array_unique($sources);

        return [...array_intersect(array_keys(self::SOURCES), $sources), ...array_diff($sources, array_keys(self::SOURCES))];
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
