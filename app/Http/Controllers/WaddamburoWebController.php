<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\PlayerRankSnapshot;
use App\Models\PlayerVersionStats;
use App\Models\Sanction;
use App\Models\User;
use App\Models\WdbChart;
use App\Models\WdbDanCourse;
use App\Models\WdbDanPlay;
use App\Models\WdbPlay;
use App\Models\WdbSong;
use App\Services\PlayerRankAggregateService;
use App\Services\WaddamburoAiBattles;
use App\Services\WaddamburoDanRank;
use App\Services\WaddamburoRankAggregateService;
use App\Services\WaddamburoSongs;
use App\Services\WaddamburoStatus;
use Carbon\Carbon;
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
 * WaddamburoSongs) with its charts, one per course (osu!: one per difficulty). Only ranked charts count towards
 * the rankings, and the pages show a viewer only the charts WdbChart::visibleTo() lets them see (so a
 * song of custom charts is gone for whoever never played it). title is the original (Japanese) title,
 * title_en the English one when the client knows it.
 */
class WaddamburoWebController extends Controller
{
    /** Chart sources as the pages show them (see shownSource) => label. */
    private const SOURCES = ['Stock' => 'Stock', 'Nijiiro' => 'Nijiiro', 'Wii' => 'Wii', 'WiiU' => 'Wii U', 'OsuLazer' => 'osu!lazer', 'Custom' => 'Custom'];

    /** The Wii and Wii U releases (a chart link's release, see WaddamburoSongs::wii) => name. */
    private const RELEASES = ['wii1' => 'Wii (2008)', 'wii2' => 'Wii 2', 'wii3' => 'Wii 3', 'wii4' => 'Wii Ketteiban',
        'wii5' => 'Wii Chou Goukaban', 'wiiu' => 'Wii U', 'wiiu2' => 'Wii U Tokumori', 'wiiu3' => 'Wii U Atsumete'];

    /** shownSource() in SQL, over a wdb_chart_song link joined to its chart. */
    private const SHOWN_SOURCE_SQL = "CASE WHEN wdb_chart_song.source = 'OsuLazer' THEN 'OsuLazer' "
        ."WHEN wdb_chart_song.source IN ('Stock', 'Nijiiro', 'Wii', 'WiiU') AND wdb_charts.is_stock THEN wdb_chart_song.source ELSE 'Custom' END";

    public function __construct(private readonly WaddamburoAiBattles $battles) {}

    public function rankings(WaddamburoRankAggregateService $aggregates): Response
    {
        $stats = $aggregates->standings($this->viewerBaid())->take(100)->values();
        $users = User::query()->whereIn('id', $stats->pluck('user_id'))->get()->keyBy('id');

        return Inertia::render('Rankings', [
            'gameVersion' => $this->scope(),
            'danRanks' => app(WaddamburoDanRank::class)->forUsers($stats->pluck('user_id')),
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
        $visible = WdbChart::visibleTo($request->user());
        $plays = $this->songPlays($visible);
        $songs = WdbSong::query()
            ->whereHas('charts', fn (Builder $chart) => $chart->whereIn('wdb_charts.id', $visible))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->whereLike('title', "%{$search}%")->orWhereLike('title_en', "%{$search}%")
                ->orWhereLike('subtitle', "%{$search}%")->orWhereLike('subtitle_en', "%{$search}%")))
            ->when($source !== null, fn (Builder $query) => $query->whereExists(fn (QueryBuilder $link) => $link
                ->from('wdb_chart_song')->join('wdb_charts', 'wdb_charts.id', '=', 'wdb_chart_song.wdb_chart_id')
                ->whereColumn('wdb_chart_song.wdb_song_id', 'wdb_songs.id')->whereIn('wdb_charts.id', $visible)
                ->whereRaw(self::SHOWN_SOURCE_SQL.' = ?', [$source])))
            ->select('*')
            ->selectSub($plays('COUNT(*)'), 'play_count')
            ->selectSub($plays('COUNT(DISTINCT wdb_plays.baid)'), 'player_count')
            ->orderByDesc('created_at')->orderBy('id')
            ->paginate(40)
            ->withQueryString();
        $card = $this->songCard($songs->pluck('id')->all(), $visible);
        $songs->through($card);

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

    /**
     * The song list's cards for these stock songs (musicids), in the order given, those with a chart the viewer may see
     * (the events page's songs).
     *
     * @param  list<string>  $musicIds
     * @return list<array<string, mixed>>
     */
    public function stockSongCards(Request $request, array $musicIds): array
    {
        $visible = WdbChart::visibleTo($request->user());
        $plays = $this->songPlays($visible);
        $songs = WdbSong::query()->where('family', WaddamburoSongs::family('Stock'))->whereIn('song_key', $musicIds)
            ->whereHas('charts', fn (Builder $chart) => $chart->whereIn('wdb_charts.id', $visible))
            ->select('*')
            ->selectSub($plays('COUNT(*)'), 'play_count')
            ->selectSub($plays('COUNT(DISTINCT wdb_plays.baid)'), 'player_count')
            ->get()->sortBy(fn (WdbSong $song): int|false => array_search($song->song_key, $musicIds, true));
        $card = $this->songCard($songs->pluck('id')->all(), $visible);

        return $songs->map($card)->values()->all();
    }

    /** Plays of a song's visible charts (a chart linked once per source still counts its plays once). */
    private function songPlays(mixed $visible): \Closure
    {
        return fn (string $aggregate): QueryBuilder => DB::table('wdb_plays')
            ->whereIn('wdb_plays.wdb_chart_id', DB::table('wdb_chart_song')->select('wdb_chart_id')
                ->whereColumn('wdb_chart_song.wdb_song_id', 'wdb_songs.id'))
            ->whereIn('wdb_plays.wdb_chart_id', $visible)
            ->tap(fn (QueryBuilder $query) => WdbPlay::visibility($query, $this->viewerBaid()))
            ->selectRaw($aggregate);
    }

    /**
     * A song list card for each song (its sources as the genre badge).
     *
     * @param  list<int>  $songIds
     * @return \Closure(WdbSong): array<string, mixed>
     */
    private function songCard(array $songIds, mixed $visible): \Closure
    {
        $sources = DB::table('wdb_chart_song')->join('wdb_charts', 'wdb_charts.id', '=', 'wdb_chart_song.wdb_chart_id')
            ->whereIn('wdb_song_id', $songIds)->whereIn('wdb_charts.id', $visible)
            ->selectRaw('DISTINCT wdb_chart_song.wdb_song_id, '.self::SHOWN_SOURCE_SQL.' AS source')
            ->get()->groupBy('wdb_song_id')->map->pluck('source');

        return fn (WdbSong $song): array => [
            'id' => $song->id,
            'song_no' => $song->id,
            'title' => (string) ($song->title ?? $song->title_en),
            'title_en' => $song->title_en,
            'genre' => $this->genre($sources->get($song->id, collect())->all()),
            'play_count' => (int) $song->play_count,
            'player_count' => (int) $song->player_count,
            'is_favorite' => false,
        ];
    }

    public function song(Request $request, string $id): Response
    {
        $song = WdbSong::query()->findOrFail($id);
        $visible = $song->charts()->whereIn('wdb_charts.id', WdbChart::visibleTo($request->user()))->get();
        abort_if($visible->isEmpty(), 404);
        // One entry per chart, with every source that ships it in this song.
        $charts = $visible->groupBy('id')->map(function (Collection $links): WdbChart {
            $chart = $links->first();
            $chart->setAttribute('sources', self::ordered($links->map(fn (WdbChart $link): string => self::shownSource($link->pivot->source, $link->is_stock))->all()));
            $chart->setAttribute('releases', $links->map(fn (WdbChart $link): string => $link->pivot->release ?? '')->filter()->unique()
                ->sortBy(fn (string $release): int|false => array_search($release, array_keys(self::RELEASES), true))->values()->all());

            return $chart;
        })->values();
        $chartIds = $charts->pluck('id');
        $songSources = self::ordered($charts->pluck('sources')->flatten()->all());
        $plays = WdbPlay::query()->whereIn('wdb_chart_id', $chartIds)->visibleTo($this->viewerBaid());

        [$difficulties, $versions, $boards] = $this->difficultyBoards($charts);
        $recentPlays = $this->songRecentPlays($chartIds);
        $userIds = collect($difficulties)->concat($boards)->flatMap(fn (array $board): array => [
            ...$board['entries'], ...$board['shinuchi_entries'],
            ...collect($board['legacy'] ?? [])->flatMap(fn (array $legacy): array => [...$legacy['entries'], ...$legacy['shinuchi_entries']])->all(),
        ])->merge($recentPlays)->pluck('user_id');

        return Inertia::render('SongDetail', [
            'danRanks' => app(WaddamburoDanRank::class)->forUsers($userIds),
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
            'difficulties' => $difficulties,
            // The systems charting the song differently: Standard (what they share) first, then each outlier; boards by id.
            'versions' => $versions,
            'boards' => $boards,
            'recentPlays' => $recentPlays,
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
        $visible = WdbChart::visibleTo($request->user());
        $standings = $aggregates->standings($this->viewerBaid());
        $summary = $player instanceof Player ? $standings->firstWhere('user_id', $user->id) : null;

        return Inertia::render('Board', [
            'danRanks' => app(WaddamburoDanRank::class)->forUsers([$user->id]),
            'profile' => [
                'id' => $user->id, 'name' => $user->name, 'avatar' => $user->avatar,
                'mydon_name' => $player?->mydon_name, 'game_version' => $this->scope(),
                'last_played_at' => $player instanceof Player ? WdbPlay::query()->where('baid', $player->baid)->whereIn('wdb_chart_id', $visible)->visibleTo($this->viewerBaid())->max('played_at') : null,
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
            'recentPlays' => $player instanceof Player ? $this->playerRecentPlays($player, $visible) : [],
            'bestPerformances' => $player instanceof Player ? $this->playerBests($player, $visible) : [],
            'aiBattleData' => $player instanceof Player ? $this->aiBattleData($player, $visible) : null,
            'blueBattleData' => null, 'greenGhostData' => null, 'tokkunData' => null, 'daniData' => null,
            // Staff get a way into the player's moderation page.
            'danProgress' => $player instanceof Player ? $this->danProgress($player) : [],
            'displayDanRank' => $player instanceof Player ? app(WaddamburoDanRank::class)->selected($player) : null,
            'canChooseDanRank' => $player instanceof Player && $request->user()?->id === $user->id,
            'moderationBaid' => $request->user()?->isStaff() === true && $player instanceof Player ? (int) $player->baid : null,
            'standing' => Sanction::standingFor($user, $request->user()),
            'onlineStatus' => app(WaddamburoStatus::class)->of($user),
        ]);
    }

    /**
     * The song's boards, one card per course: the chart most systems share. A legacy chart's (played before
     * Waddamburo read the chart right, e.g. a 譜面分岐 chart as its normal route) goes under its course's card as
     * `legacy`; one whose course has none stands alone, marked legacy. When the systems chart the song differently,
     * also its versions (see versions()) and every current chart's board by id.
     *
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>, array<int, array<string, mixed>>}
     */
    private function difficultyBoards(Collection $charts): array
    {
        $sorted = $charts->sortBy(fn (WdbChart $chart): array => [
            $chart->course, -count($chart->sources ?? []), array_search($chart->sources[0] ?? '', array_keys(self::SOURCES), true), $chart->id,
        ]);
        [$legacy, $current] = $sorted->partition(fn (WdbChart $chart): bool => $chart->legacy_at !== null);
        $courses = $current->groupBy('course')->map(fn (Collection $course): Collection => $course->values());
        $versions = self::versions($courses);
        $boards = $versions === [] ? [] : $current->mapWithKeys(fn (WdbChart $chart): array => [$chart->id => $this->difficultyBoard($chart)])->all();
        $cards = $courses->map(fn (Collection $course): array => [
            ...($boards[$course->first()->id] ?? $this->difficultyBoard($course->first())),
            'legacy' => $legacy->where('course', $course->first()->course)->map(fn (WdbChart $old): array => $this->difficultyBoard($old))->values()->all(),
        ]);
        $orphans = $legacy->reject(fn (WdbChart $chart): bool => $cards->has($chart->course))
            ->map(fn (WdbChart $chart): array => [...$this->difficultyBoard($chart), 'is_legacy' => true]);

        return [$cards->values()->concat($orphans)->sortBy('level')->values()->all(), $versions, $boards];
    }

    /**
     * The song's versions, empty when every system ships the same charts. A system is a source, or a Wii/Wii U
     * release; Standard is the systems whose every course is the course's most shared chart, each outlier the systems
     * with the same charts as each other. Each version: key (for the URL), label, its chart id by level, and the
     * levels where it differs from Standard.
     *
     * @param  Collection<int, Collection<int, WdbChart>>  $courses  the current charts by course, most shared first
     * @return list<array{key: string, label: string, charts: array<int, int>, differs: list<int>}>
     */
    private static function versions(Collection $courses): array
    {
        // Each system's chart per level; a system with two charts of a course has its second as "Stock#2".
        $systems = [];
        foreach ($courses as $course => $charts) {
            foreach ($charts as $chart) {
                $releases = $chart->releases ?? [];
                $keys = [...array_diff($chart->sources ?? [], ['Wii', 'WiiU']), ...$releases];
                foreach ($keys as $key) {
                    $n = 1;
                    while (isset($systems[$n === 1 ? $key : "{$key}#{$n}"][$course + 1])) {
                        $n++;
                    }
                    $systems[$n === 1 ? $key : "{$key}#{$n}"][$course + 1] = (int) $chart->id;
                }
            }
        }
        $standard = $courses->mapWithKeys(fn (Collection $charts, int $course): array => [$course + 1 => (int) $charts->first()->id])->all();
        $groups = ['standard' => []];
        foreach ($systems as $key => $charts) {
            $same = array_intersect_assoc($charts, $standard) === $charts;
            $groups[$same ? 'standard' : json_encode($charts)][] = $key;
        }
        if (count($groups) === 1) {
            return [];
        }
        $releases = array_keys(array_filter($systems, fn (string $key): bool => isset(self::RELEASES[$key]), ARRAY_FILTER_USE_KEY));

        return array_values(array_map(function (string $group, array $members) use ($systems, $standard, $releases): array {
            $charts = $group === 'standard' ? $standard : $systems[$members[0]];
            ksort($charts);

            return [
                'key' => $group === 'standard' ? 'standard' : strtolower(str_replace('#', '-', implode('_', $members))),
                'label' => ($group === 'standard' ? 'Standard: ' : '').self::systemsLabel($members, $releases),
                'charts' => $charts,
                'differs' => array_keys(array_diff_assoc($charts, $standard)),
            ];
        }, array_keys($groups), $groups));
    }

    /**
     * Systems as a version reads them: sources by name, a system's Wii releases as "Wii" when it has them all, else
     * each release's name; a second chart of a source as "Stock (2)".
     *
     * @param  list<string>  $members
     * @param  list<string>  $releases  every release of the song
     */
    private static function systemsLabel(array $members, array $releases): string
    {
        $names = [];
        foreach (['Wii' => false, 'WiiU' => true] as $system => $isU) {
            $all = array_filter($releases, fn (string $release): bool => str_starts_with($release, 'wiiu') === $isU);
            $mine = array_values(array_intersect($all, $members));
            if ($mine !== [] && count($mine) === count($all)) {
                $members = array_diff($members, $mine);
                $members[] = $system;
            }
        }
        // Sources in SOURCES order, a release with its system.
        $rank = function (string $member): array {
            $key = explode('#', $member)[0];
            $system = isset(self::RELEASES[$key]) ? (str_starts_with($key, 'wiiu') ? 'WiiU' : 'Wii') : $key;

            return [array_search($system, array_keys(self::SOURCES), true), array_search($key, array_keys(self::RELEASES), true), $member];
        };
        usort($members, fn (string $a, string $b): int => $rank($a) <=> $rank($b));
        foreach ($members as $member) {
            [$key, $n] = [...explode('#', $member), null];
            $names[] = (self::SOURCES[$key] ?? self::RELEASES[$key] ?? $key).($n === null ? '' : " ({$n})");
        }

        return implode(' · ', $names);
    }

    private function difficultyBoard(WdbChart $chart): array
    {
        $viewerBaid = $this->viewerBaid();
        $rows = WaddamburoRankAggregateService::bests(fn (QueryBuilder $query) => $query->where('wdb_plays.wdb_chart_id', $chart->id), viewerBaid: $viewerBaid)
            ->orderByDesc('best_score')
            ->get();
        $shinuchi = WaddamburoRankAggregateService::bests(fn (QueryBuilder $query) => $query->where('wdb_plays.wdb_chart_id', $chart->id), shinuchi: true, viewerBaid: $viewerBaid)
            ->orderByDesc('best_score')
            ->get();
        $players = Player::query()->whereIn('baid', $rows->pluck('baid')->merge($shinuchi->pluck('baid')))->with('user')->get()->keyBy('baid');
        // Each player's best play itself (the earlier one on a tie, as the game's leaderboard), for its counts.
        $bestPlays = fn (bool $shinuchi): Collection => WdbPlay::query()
            ->fromSub(WdbPlay::query()->select(['id', 'baid', 'mode', 'great', 'good', 'miss', 'max_combo', 'rolls'])
                ->selectRaw('ROW_NUMBER() OVER (PARTITION BY baid ORDER BY score DESC, played_at ASC) AS player_best')
                ->where('wdb_chart_id', $chart->id)->whereNotNull('rescored_at')->visibleTo($viewerBaid)
                ->whereRaw('(options & ?) '.($shinuchi ? '<>' : '=').' 0', [WdbPlay::SHINUCHI]), 'wdb_plays')
            ->where('player_best', 1)->get()->keyBy('baid');
        $entries = fn (Collection $rows, Collection $plays): array => $rows->filter(fn ($row) => $players->get($row->baid)?->user !== null)->take(20)->values()->map(function ($row, int $index) use ($players, $plays, $chart): array {
            $user = $players->get($row->baid)->user;
            $play = $plays->get($row->baid);

            return [
                'rank' => $index + 1, 'user_id' => (int) $user->id, 'play_id' => $play?->id, 'mode' => $play?->mode,
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
            'play_count' => WdbPlay::query()->where('wdb_chart_id', $chart->id)->visibleTo($viewerBaid)->count(),
            'player_count' => $rows->count(),
            'crown_counts' => ['clear' => $rows->where('best_crown', 1)->count(), 'gold' => $rows->where('best_crown', 2)->count(), 'dondaful' => $rows->where('best_crown', 3)->count()],
            'entries' => $entries($rows, $bestPlays(false)),
            // 真打 plays rank on their own board (another scale).
            'shinuchi_entries' => $entries($shinuchi, $bestPlays(true)),
        ];
    }

    private function songRecentPlays(Collection $chartIds): array
    {
        return WdbPlay::query()->whereIn('wdb_chart_id', $chartIds)->visibleTo($this->viewerBaid())->with(['player.user', 'chart'])->latest('played_at')->limit(15)->get()
            ->filter(fn (WdbPlay $play) => $play->player?->user !== null)
            ->map(fn (WdbPlay $play): array => [
                'play_id' => $play->id,
                'user_id' => (int) $play->player->user_id, 'player_name' => $play->player->user->name,
                'avatar' => $play->player->user->avatar, 'level' => (int) $play->course + 1,
                'difficulty' => $play->chart?->difficulty,
                'played_at' => $play->played_at?->toDateTimeString(), 'play_result' => self::crown($play),
                'score' => (int) $play->score, 'score_rank' => 0, 'awaiting_verification' => $play->rescored_at === null,
                'precision' => PlayerRankAggregateService::precision((int) $play->great, (int) $play->good, (int) $play->miss),
                'options' => WdbPlay::optionLabels((int) $play->options),
            ])->values()->all();
    }

    /** @param  QueryBuilder  $visible  the chart ids the viewer may see (WdbChart::visibleTo) */
    private function playerRecentPlays(Player $player, QueryBuilder $visible): array
    {
        return WdbPlay::query()->where('baid', $player->baid)->with('chart')
            ->whereIn('wdb_chart_id', $visible)->visibleTo($this->viewerBaid())
            ->latest('played_at')->limit(10)->get()
            ->map(fn (WdbPlay $play): array => [
                'play_id' => $play->id,
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
                'awaiting_verification' => $play->rescored_at === null,
                'options' => WdbPlay::optionLabels((int) $play->options),
            ])->all();
    }

    /** @return array{progress: array<string, int>, recent: list<array<string, mixed>>} */
    private function aiBattleData(Player $player, QueryBuilder $visible): array
    {
        $recent = DB::table('wdb_plays')
            ->join('wdb_charts', 'wdb_charts.id', '=', 'wdb_plays.wdb_chart_id')
            ->leftJoin('wdb_ai_battles', 'wdb_ai_battles.id', '=', 'wdb_plays.id')
            ->where('wdb_plays.baid', $player->baid)->where('wdb_plays.mode', 'ai_battle')
            ->whereIn('wdb_plays.wdb_chart_id', $visible)
            ->tap(fn (QueryBuilder $query) => WdbPlay::visibility($query, $this->viewerBaid()))
            ->orderByDesc('wdb_plays.played_at')->orderByDesc('wdb_plays.id')->limit(20)
            ->get(['wdb_plays.id', 'wdb_plays.course', 'wdb_plays.score', 'wdb_plays.cleared',
                'wdb_plays.played_at', 'wdb_plays.options', 'wdb_charts.title', 'wdb_charts.title_en', 'wdb_charts.level',
                'wdb_ai_battles.verified_at', 'wdb_ai_battles.eligible', 'wdb_ai_battles.won',
                'wdb_ai_battles.sections', 'wdb_ai_battles.points', 'wdb_ai_battles.support_level'])
            ->map(function (object $play): array {
                $verified = $play->verified_at !== null;
                $counted = $verified && (bool) $play->eligible;

                return [
                    'play_id' => $play->id, 'title' => $play->title ?? $play->title_en ?? 'Unknown chart',
                    'course' => (int) $play->course, 'stars' => $play->level === null ? null : (int) $play->level,
                    'score' => (int) $play->score, 'cleared' => (bool) $play->cleared,
                    'played_at' => Carbon::parse($play->played_at)->toIso8601String(),
                    'options' => WdbPlay::optionLabels((int) $play->options),
                    'verified' => $verified, 'counted' => $counted,
                    'won' => $verified ? (bool) $play->won : null,
                    'sections' => $verified ? $play->sections : '',
                    'points' => $counted ? (int) $play->points : 0,
                    'support_level' => $verified ? (int) $play->support_level : null,
                ];
            })->all();

        return ['progress' => $this->battles->progress((int) $player->baid, public: $this->viewerBaid() !== (int) $player->baid),
            'recent' => $recent];
    }

    /**
     * Every dan series as the board's medals show it, from the plays the server has scored. The ranks are the
     * ladder and set a series' progress; challenges are the extras around it. A course in several series counts in each.
     *
     * @return list<array<string, mixed>>
     */
    private function danProgress(Player $player): array
    {
        $bests = WdbDanPlay::query()->where('baid', $player->baid)->whereNotNull('rescored_at')->whereNull('hidden_at')->where('result_level', '>', 0)
            ->groupBy('content_hash')
            ->selectRaw('content_hash, MAX(result_level) AS best, MAX(CASE WHEN '.WdbDanPlay::PERFECT_CLEAR_SQL.' THEN 1 ELSE 0 END) AS perfect')
            ->get()->keyBy('content_hash');
        // The run a course links to: the player's best.
        $runs = WdbDanPlay::query()->where('baid', $player->baid)->whereNotNull('rescored_at')->whereNull('hidden_at')->where('result_level', '>', 0)
            ->select('wdb_dan_plays.*')->selectRaw('CASE WHEN '.WdbDanPlay::PERFECT_CLEAR_SQL.' THEN 1 ELSE 0 END AS perfect_clear')
            ->orderBy('perfect_clear')->orderBy('result_level')->orderBy('score')->pluck('id', 'content_hash');
        $courses = WdbDanCourse::query()->orderBy('level')->get(['series', 'level', 'name', 'content_hash'])->groupBy('series');
        $progress = [];
        $disabled = WdbDanCourse::disabledSeries();
        foreach (WdbDanCourse::SERIES as $id => $name) {
            if (! $courses->has($id) || in_array($id, $disabled, true)) {
                continue;
            }
            $rows = $courses[$id]->map(fn (WdbDanCourse $course): array => [
                'level' => $course->level,
                'name' => $course->name,
                'label' => WdbDanCourse::RANK_LABELS[$course->name] ?? null,
                'title' => WdbDanCourse::challengeTitle($course->name),
                'run' => $runs[$course->content_hash] ?? null,
                'best' => $bests->has($course->content_hash) ? (int) $bests[$course->content_hash]->best : null,
                'perfect' => (bool) ($bests->get($course->content_hash)?->perfect ?? false),
            ])->values();
            $ranks = $rows->whereNotNull('label');
            $challenges = $rows->whereNull('label');
            $progress[] = [
                'id' => $id,
                'name' => $name,
                'ranks_total' => $ranks->count(),
                'ranks_passed' => $ranks->where('best', '>=', WdbDanPlay::RED)->count(),
                'ranks_gold' => $ranks->where('best', WdbDanPlay::GOLD)->count(),
                'ranks_perfect' => $ranks->where('perfect', true)->count(),
                'challenges_total' => $challenges->count(),
                'challenges_passed' => $challenges->where('best', '>=', WdbDanPlay::RED)->count(),
                // The ladder runs in level order: the highest passed is the player's rank.
                'top_rank' => $ranks->where('best', '>=', WdbDanPlay::RED)->last()['label'] ?? null,
                'top_perfect' => $ranks->where('best', '>=', WdbDanPlay::RED)->last()['perfect'] ?? false,
                'courses' => $rows->all(),
            ];
        }

        return $progress;
    }

    /** @param  QueryBuilder  $visible  the chart ids the viewer may see (WdbChart::visibleTo) */
    private function playerBests(Player $player, QueryBuilder $visible): array
    {
        $rows = WaddamburoRankAggregateService::bests(fn (QueryBuilder $query) => $query
            ->where('wdb_plays.baid', $player->baid)
            ->whereIn('wdb_plays.wdb_chart_id', $visible), viewerBaid: $this->viewerBaid())
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
                    ->where('wdb_plays.wdb_chart_id', $row->wdb_chart_id), viewerBaid: $this->viewerBaid()), 'bests')
                    ->where('best_score', '>', $row->best_score)->count() + 1,
            ];
        })->all();
    }

    /** The viewer's own baid: their own plays stay visible to them (WdbPlay::visibleTo). */
    private function viewerBaid(): ?int
    {
        $baid = request()->user()?->player?->baid;

        return $baid === null ? null : (int) $baid;
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
     * A chart link's source as the pages show it: Stock, Nijiiro, Wii and WiiU for official charts only, osu! for
     * osu! beatmaps (their own ranked maps), and Custom for the rest (TJA, charts added to a game install).
     */
    private static function shownSource(string $source, bool $isStock): string
    {
        return match (true) {
            $source === 'OsuLazer' => 'OsuLazer',
            in_array($source, ['Stock', 'Nijiiro', 'Wii', 'WiiU'], true) && $isStock => $source,
            default => 'Custom',
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
