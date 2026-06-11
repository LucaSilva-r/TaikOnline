<?php

namespace App\Http\Controllers;

use App\Enums\TaikoGameVersion;
use App\Models\SongBest;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RankingController extends Controller
{
    public function index(Request $request): Response
    {
        $version = $request->attributes->get('taikoGameVersion');
        if (! $version instanceof TaikoGameVersion) {
            abort(404);
        }

        $rows = SongBest::query()
            ->where('song_bests.game_version', $version->value)
            ->join('players', 'players.baid', '=', 'song_bests.baid')
            ->join('users', 'users.id', '=', 'players.user_id')
            ->leftJoin('songs', function (JoinClause $join): void {
                $join->on('songs.version', '=', 'song_bests.game_version')
                    ->on('songs.song_no', '=', 'song_bests.song_no');
            })
            ->select([
                'song_bests.baid',
                'song_bests.game_version',
                'song_bests.song_no',
                'song_bests.level',
                'song_bests.best_score',
                'song_bests.best_score_rank',
                'users.name as player_name',
                'songs.title as song_title',
            ])
            ->orderByDesc('song_bests.best_score')
            ->limit(500)
            ->get();

        $songGroups = $rows
            ->groupBy(fn (SongBest $best): string => (string) ($best->song_title ?: "#{$best->song_no}"))
            ->map(fn ($songRows, string $title): array => [
                'title' => $title,
                'versions' => $songRows
                    ->groupBy(fn (SongBest $best): string => "{$best->game_version}:{$best->song_no}:{$best->level}")
                    ->map(fn ($versionRows): array => [
                        'game_version' => (string) $versionRows->first()->game_version,
                        'song_no' => (int) $versionRows->first()->song_no,
                        'level' => (int) $versionRows->first()->level,
                        'entries' => $versionRows
                            ->sortByDesc('best_score')
                            ->take(10)
                            ->values()
                            ->map(fn (SongBest $best, int $index): array => [
                                'rank' => $index + 1,
                                'baid' => (int) $best->baid,
                                'player_name' => $best->player_name,
                                'score' => (int) $best->best_score,
                                'score_rank' => (int) $best->best_score_rank,
                            ])
                            ->all(),
                    ])
                    ->sortBy([
                        ['game_version', 'asc'],
                        ['song_no', 'asc'],
                        ['level', 'asc'],
                    ])
                    ->values()
                    ->all(),
            ])
            ->sortBy('title')
            ->values()
            ->take(50)
            ->all();

        return Inertia::render('Rankings', [
            'songGroups' => $songGroups,
        ]);
    }
}
