<?php

namespace App\Http\Controllers;

use App\Enums\TaikoGameVersion;
use App\Models\Player;
use App\Models\Song;
use App\Models\SongBest;
use App\Models\SongPlayResult;
use App\Models\User;
use App\Services\PlayerRankAggregateService;
use App\Support\SongSearch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SongCatalogController extends Controller
{
    /**
     * Top scores shown per difficulty on the song detail page.
     */
    private const LEADERBOARD_SIZE = 20;

    public function index(Request $request): Response
    {
        $version = $this->resolveVersion($request);
        $search = trim((string) $request->query('q', ''));

        $favorites = $this->favoriteState($request, $version);

        $resultsTable = (new SongPlayResult)->getTable();

        $playStats = DB::table($resultsTable)
            ->where('game_version', $version->value)
            ->groupBy('song_no')
            ->select('song_no')
            ->selectRaw('COUNT(*) as play_count')
            ->selectRaw('COUNT(DISTINCT baid) as player_count');

        $songs = Song::query()
            ->where('songs.version', $version->value)
            ->when($search !== '', function ($query) use ($search): void {
                $normalized = SongSearch::normalize($search);
                $query->where('songs.search_index', 'like', "%{$normalized}%");
            })
            ->leftJoinSub($playStats, 'play_stats', 'play_stats.song_no', '=', 'songs.song_no')
            ->select('songs.*', 'play_stats.play_count', 'play_stats.player_count')
            ->orderByRaw('COALESCE(play_stats.play_count, 0) DESC')
            ->orderBy('songs.song_no')
            ->paginate(40)
            ->withQueryString()
            ->through(fn (Song $song): array => [
                'id' => $song->id,
                'song_no' => $song->song_no,
                'title' => $song->title,
                'title_en' => $song->title_en,
                'genre' => [
                    'value' => $song->genre->value,
                    'label' => $song->genre->label(),
                ],
                'play_count' => (int) ($song->getAttribute('play_count') ?? 0),
                'player_count' => (int) ($song->getAttribute('player_count') ?? 0),
                'is_favorite' => in_array((int) $song->song_no, $favorites['numbers'], true),
            ]);

        return Inertia::render('Songs', [
            'gameVersion' => [
                'value' => $version->value,
                'label' => $version->label(),
            ],
            'songs' => $songs,
            'filters' => [
                'q' => $search,
            ],
            'canFavorite' => $favorites['can_favorite'],
            'favoriteLimit' => $favorites['limit'],
            'favoriteCount' => $favorites['count'],
        ]);
    }

    public function show(Request $request, string $song): Response|RedirectResponse
    {
        $version = $this->resolveVersion($request);

        $resolved = Song::query()->find($song);

        // The song id is version-specific, so switching the game version keeps an
        // id that no longer matches. Send the player to the same song in the new
        // version when it exists, otherwise back to the catalogue.
        if ($resolved === null || $resolved->version !== $version->value) {
            $equivalent = $resolved !== null
                ? Song::query()
                    ->where('version', $version->value)
                    ->where('unique_id', $resolved->unique_id)
                    ->first()
                : null;

            if ($equivalent !== null) {
                return redirect()->route('songs.show', [
                    'taikoVersion' => $version->value,
                    'song' => $equivalent->id,
                ]);
            }

            return redirect()->route('songs.index', ['taikoVersion' => $version->value]);
        }

        $song = $resolved;

        $favorites = $this->favoriteState($request, $version);

        return Inertia::render('SongDetail', [
            'gameVersion' => [
                'value' => $version->value,
                'label' => $version->label(),
            ],
            'song' => [
                'id' => $song->id,
                'song_no' => $song->song_no,
                'title' => $song->title,
                'title_en' => $song->title_en,
                'genre' => [
                    'value' => $song->genre->value,
                    'label' => $song->genre->label(),
                    'label_jp' => $song->genre->labelJp(),
                ],
            ],
            'summary' => $this->summary($version, $song),
            'difficulties' => $this->difficulties($version, $song),
            'recentPlays' => $this->recentPlays($version, $song),
            'isFavorite' => in_array((int) $song->song_no, $favorites['numbers'], true),
            'canFavorite' => $favorites['can_favorite'],
            'favoriteLimit' => $favorites['limit'],
            'favoriteCount' => $favorites['count'],
        ]);
    }

    /**
     * Toggle the authenticated player's favourite status for a song. Favourites
     * are version-scoped (each version ships a different library) and capped at
     * the version's in-game maximum, so adding past the cap is rejected.
     */
    public function toggleFavorite(Request $request, string $song): RedirectResponse
    {
        $version = $this->resolveVersion($request);

        $resolved = Song::query()->find($song);
        if ($resolved === null || $resolved->version !== $version->value) {
            abort(404);
        }

        $player = $request->user()?->player;
        if ($player === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Link a game card before favouriting songs.')]);

            return back();
        }

        $songNo = (int) $resolved->song_no;

        $existing = $player->favoriteSongs()
            ->where('game_version', $version->value)
            ->where('song_no', $songNo)
            ->first();

        if ($existing !== null) {
            $existing->delete();

            return back();
        }

        $limit = $version->favoriteSongLimit();
        $count = $player->favoriteSongs()->where('game_version', $version->value)->count();

        if ($count >= $limit) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('You can only favourite :limit songs in :version. Remove one first.', [
                'limit' => $limit,
                'version' => $version->label(),
            ])]);

            return back();
        }

        $player->favoriteSongs()->create([
            'game_version' => $version->value,
            'song_no' => $songNo,
        ]);

        return back();
    }

    private function resolveVersion(Request $request): TaikoGameVersion
    {
        $version = $request->attributes->get('taikoGameVersion');
        if (! $version instanceof TaikoGameVersion || (bool) $request->attributes->get('taikoVersionIsAll', false)) {
            abort(404);
        }

        return $version;
    }

    /**
     * Resolve the authenticated player's favourite song numbers for this version,
     * whether they may favourite (a player record requires a linked game card),
     * and the version's favourite cap with the player's current usage.
     *
     * @return array{numbers: list<int>, can_favorite: bool, limit: int, count: int}
     */
    private function favoriteState(Request $request, TaikoGameVersion $version): array
    {
        $player = $request->user()?->player;

        $numbers = $player === null
            ? []
            : $player->favoriteSongs()
                ->where('game_version', $version->value)
                ->orderBy('id')
                ->pluck('song_no')
                ->map(fn (mixed $no): int => (int) $no)
                ->all();

        return [
            'numbers' => $numbers,
            'can_favorite' => $player !== null,
            'limit' => $version->favoriteSongLimit(),
            'count' => count($numbers),
        ];
    }

    /**
     * @return array{total_plays: int, unique_players: int, first_played_at: ?string, last_played_at: ?string}
     */
    private function summary(TaikoGameVersion $version, Song $song): array
    {
        $row = SongPlayResult::query()
            ->where('game_version', $version->value)
            ->where('song_no', $song->song_no)
            ->selectRaw('COUNT(*) as total_plays')
            ->selectRaw('COUNT(DISTINCT baid) as unique_players')
            ->selectRaw('MIN(played_at) as first_played_at')
            ->selectRaw('MAX(played_at) as last_played_at')
            ->first();

        return [
            'total_plays' => (int) ($row->total_plays ?? 0),
            'unique_players' => (int) ($row->unique_players ?? 0),
            'first_played_at' => $row?->first_played_at ? (string) $row->first_played_at : null,
            'last_played_at' => $row?->last_played_at ? (string) $row->last_played_at : null,
        ];
    }

    /**
     * Per-difficulty leaderboards, play counts and crown distribution.
     *
     * @return list<array<string, mixed>>
     */
    private function difficulties(TaikoGameVersion $version, Song $song): array
    {
        $playersTable = (new Player)->getTable();
        $bestsTable = (new SongBest)->getTable();
        $resultsTable = (new SongPlayResult)->getTable();

        $rows = SongBest::query()
            ->where("{$bestsTable}.game_version", $version->value)
            ->where("{$bestsTable}.song_no", $song->song_no)
            ->join($playersTable, "{$playersTable}.baid", '=', "{$bestsTable}.baid")
            ->whereNotNull("{$playersTable}.user_id")
            ->select([
                "{$bestsTable}.baid",
                "{$bestsTable}.level",
                "{$bestsTable}.best_score",
                "{$bestsTable}.best_score_rank",
                "{$bestsTable}.best_crown",
                "{$playersTable}.user_id",
            ])
            ->orderBy("{$bestsTable}.level")
            ->orderByDesc("{$bestsTable}.best_score")
            ->get();

        $precisionByBest = $this->precisionByBestPlay($version, $song);

        $playCountsByLevel = DB::table($resultsTable)
            ->where('game_version', $version->value)
            ->where('song_no', $song->song_no)
            ->groupBy('level')
            ->select('level')
            ->selectRaw('COUNT(*) as play_count')
            ->pluck('play_count', 'level');

        $users = $this->avatarMap($rows->pluck('user_id'));

        return $rows
            ->groupBy('level')
            ->map(function (Collection $levelRows, int|string $level) use ($playCountsByLevel, $users, $precisionByBest): array {
                $crownCounts = [
                    'clear' => 0,
                    'gold' => 0,
                    'dondaful' => 0,
                ];

                foreach ($levelRows as $row) {
                    match ((int) $row->best_crown) {
                        1 => $crownCounts['clear']++,
                        2 => $crownCounts['gold']++,
                        3 => $crownCounts['dondaful']++,
                        default => null,
                    };
                }

                return [
                    'level' => (int) $level,
                    'play_count' => (int) ($playCountsByLevel[$level] ?? 0),
                    'player_count' => $levelRows->count(),
                    'crown_counts' => $crownCounts,
                    'entries' => $levelRows
                        ->take(self::LEADERBOARD_SIZE)
                        ->values()
                        ->map(fn ($row, int $index): array => [
                            'rank' => $index + 1,
                            'user_id' => (int) $row->user_id,
                            'player_name' => $users->get((int) $row->user_id)['name'] ?? 'Unknown',
                            'avatar' => $users->get((int) $row->user_id)['avatar'] ?? null,
                            'score' => (int) $row->best_score,
                            'score_rank' => (int) $row->best_score_rank,
                            'crown' => (int) $row->best_crown,
                            'precision' => $precisionByBest["{$row->baid}:{$row->level}"] ?? null,
                        ])
                        ->all(),
                ];
            })
            ->sortBy('level')
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentPlays(TaikoGameVersion $version, Song $song): array
    {
        $playersTable = (new Player)->getTable();
        $resultsTable = (new SongPlayResult)->getTable();

        $rows = SongPlayResult::query()
            ->where("{$resultsTable}.game_version", $version->value)
            ->where("{$resultsTable}.song_no", $song->song_no)
            ->join($playersTable, "{$playersTable}.baid", '=', "{$resultsTable}.baid")
            ->whereNotNull("{$playersTable}.user_id")
            ->select([
                "{$resultsTable}.level",
                "{$resultsTable}.played_at",
                "{$resultsTable}.play_result",
                "{$resultsTable}.score",
                "{$resultsTable}.score_rank",
                "{$resultsTable}.good_count",
                "{$resultsTable}.ok_count",
                "{$resultsTable}.miss_count",
                "{$playersTable}.user_id",
            ])
            ->latest("{$resultsTable}.played_at")
            ->limit(15)
            ->get();

        $users = $this->avatarMap($rows->pluck('user_id'));

        return $rows
            ->map(fn ($row): array => [
                'user_id' => (int) $row->user_id,
                'player_name' => $users->get((int) $row->user_id)['name'] ?? 'Unknown',
                'avatar' => $users->get((int) $row->user_id)['avatar'] ?? null,
                'level' => (int) $row->level,
                'played_at' => $row->played_at?->toDateTimeString(),
                'play_result' => (int) $row->play_result,
                'score' => (int) $row->score,
                'score_rank' => (int) $row->score_rank,
                'precision' => PlayerRankAggregateService::precision(
                    (int) $row->good_count,
                    (int) $row->ok_count,
                    (int) $row->miss_count,
                ),
            ])
            ->all();
    }

    /**
     * Precision of each player's best-scoring play, keyed by "baid:level". Best
     * scores carry no judgement counts, so we read them from the play that set
     * the score.
     *
     * @return array<string, float>
     */
    private function precisionByBestPlay(TaikoGameVersion $version, Song $song): array
    {
        $resultsTable = (new SongPlayResult)->getTable();

        $map = [];

        SongPlayResult::query()
            ->where("{$resultsTable}.game_version", $version->value)
            ->where("{$resultsTable}.song_no", $song->song_no)
            ->select(['baid', 'level', 'score', 'good_count', 'ok_count', 'miss_count'])
            ->orderByDesc('score')
            ->get()
            ->each(function ($row) use (&$map): void {
                // First row per key is the highest score (results are score-sorted).
                $key = "{$row->baid}:{$row->level}";
                if (! array_key_exists($key, $map)) {
                    $map[$key] = PlayerRankAggregateService::precision(
                        (int) $row->good_count,
                        (int) $row->ok_count,
                        (int) $row->miss_count,
                    );
                }
            });

        return $map;
    }

    /**
     * Resolve a map of user id to display name and Don-chan avatar URL.
     *
     * @param  Collection<int, mixed>  $userIds
     * @return Collection<int, array{name: string, avatar: ?string}>
     */
    private function avatarMap(Collection $userIds): Collection
    {
        $ids = $userIds->map(fn (mixed $id): int => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $ids)
            ->get()
            ->mapWithKeys(fn (User $user): array => [
                $user->id => [
                    'name' => $user->name,
                    'avatar' => $user->avatar,
                ],
            ]);
    }
}
