<?php

namespace App\Http\Controllers;

use App\Models\WdbDanCourse;
use App\Services\WaddamburoSongs;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class WaddamburoDanController extends Controller
{
    /**
     * The dan dojo catalogue: public, since it holds no player data and a client needs it to show which
     * courses its library can play. A song's charts are the hashes that count for it: the official stock
     * charts of that song and course. A course whose songs all match a player's library is playable.
     */
    public function catalogue(): JsonResponse
    {
        $courses = WdbDanCourse::query()->with('songs')->orderBy('level')->get()->groupBy('series');
        $songKeys = $courses->flatten(1)->flatMap(fn (WdbDanCourse $course) => $course->songs->pluck('song_key'))->unique();
        $songs = DB::table('wdb_songs')->where('family', WaddamburoSongs::family('Stock'))
            ->whereIn('song_key', $songKeys)->pluck('title', 'song_key');
        $charts = DB::table('wdb_chart_song')
            ->join('wdb_songs', 'wdb_songs.id', '=', 'wdb_chart_song.wdb_song_id')
            ->join('wdb_charts', 'wdb_charts.id', '=', 'wdb_chart_song.wdb_chart_id')
            ->where('wdb_chart_song.source', 'Stock')
            ->where('wdb_songs.family', WaddamburoSongs::family('Stock'))
            ->whereIn('wdb_songs.song_key', $songKeys)
            ->where('wdb_charts.is_stock', true)
            ->whereNull('wdb_charts.legacy_at')
            ->get(['wdb_songs.song_key', 'wdb_charts.course', 'wdb_charts.sha256'])
            ->groupBy(fn (object $chart): string => $chart->song_key."\0".$chart->course);

        $series = [];
        foreach (WdbDanCourse::SERIES as $id => $name) {
            if (! $courses->has($id)) {
                continue;
            }
            $series[] = [
                'id' => $id,
                'name' => $name,
                'courses' => $courses[$id]->map(fn (WdbDanCourse $course): array => [
                    'level' => $course->level,
                    'name' => $course->name,
                    'hash' => $course->content_hash,
                    'requirements' => $course->requirements,
                    'songs' => $course->songs->map(fn ($song): array => [
                        'song_key' => $song->song_key,
                        'course' => $song->course,
                        'title' => $songs[$song->song_key] ?? null,
                        'notes' => $song->notes,
                        'charts' => ($charts[$song->song_key."\0".$song->course] ?? collect())->pluck('sha256')->values(),
                    ])->values(),
                ])->values(),
            ];
        }

        return response()->json(['series' => $series]);
    }
}
