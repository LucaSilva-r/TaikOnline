<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WdbDanCourse;
use App\Models\WdbDanCourseSong;
use App\Models\WdbDanPlay;
use App\Services\WaddamburoSongs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Which of Waddamburo's dan courses can be played, song by song, and which series the game is served. */
class WaddamburoDanController extends Controller
{
    public function index(): Response
    {
        $courses = WdbDanCourse::query()->with('songs')->orderBy('level')->get()->groupBy('series');
        $songKeys = $courses->flatten(1)->flatMap(fn (WdbDanCourse $course) => $course->songs->pluck('song_key'))->unique();
        $titles = DB::table('wdb_songs')->where('family', WaddamburoSongs::family('Stock'))
            ->whereIn('song_key', $songKeys)->pluck('title', 'song_key');
        // Charts the server has but does not count: custom, or retired.
        $known = DB::table('wdb_chart_song')
            ->join('wdb_songs', 'wdb_songs.id', '=', 'wdb_chart_song.wdb_song_id')
            ->join('wdb_charts', 'wdb_charts.id', '=', 'wdb_chart_song.wdb_chart_id')
            ->where('wdb_songs.family', WaddamburoSongs::family('Stock'))->whereIn('wdb_songs.song_key', $songKeys)
            ->get(['wdb_songs.song_key', 'wdb_charts.course'])
            ->mapWithKeys(fn (object $chart): array => [$chart->song_key."\0".$chart->course => true]);
        $runs = WdbDanPlay::query()->groupBy('content_hash')->selectRaw('content_hash, COUNT(*) AS runs')->pluck('runs', 'content_hash');
        $disabled = WdbDanCourse::disabledSeries();

        $series = [];
        foreach (WdbDanCourse::SERIES as $id => $name) {
            if (! $courses->has($id)) {
                continue;
            }
            $charts = WdbDanCourse::officialCharts($songKeys, $id);
            $rows = $courses[$id]->map(function (WdbDanCourse $course) use ($charts, $known, $titles, $runs): array {
                $songs = $course->songs->map(function (WdbDanCourseSong $song) use ($charts, $known, $titles): array {
                    $key = $song->song_key."\0".$song->course;

                    return [
                        'song_key' => $song->song_key,
                        'title' => $titles[$song->song_key] ?? null,
                        'course' => (int) $song->course,
                        'notes' => (int) $song->notes,
                        'status' => isset($charts[$key]) ? 'official' : (isset($known[$key]) ? 'custom' : 'none'),
                    ];
                })->values();

                return [
                    'level' => (int) $course->level,
                    'name' => $course->name,
                    'open' => $songs->every(fn (array $song): bool => $song['status'] === 'official'),
                    'runs' => (int) ($runs[$course->content_hash] ?? 0),
                    'songs' => $songs->all(),
                ];
            })->values();
            $series[] = [
                'id' => $id,
                'name' => $name,
                'enabled' => ! in_array($id, $disabled, true),
                'open' => $rows->where('open', true)->count(),
                'runs' => $rows->sum('runs'),
                'courses' => $rows->all(),
            ];
        }

        return Inertia::render('admin/WaddamburoDan', ['series' => $series]);
    }

    /** Starts or stops serving a series. Its runs and their pages stay. */
    public function update(Request $request, string $series): RedirectResponse
    {
        abort_unless(isset(WdbDanCourse::SERIES[$series]), 404);
        $enabled = $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        if ($enabled) {
            DB::table('wdb_dan_disabled_series')->where('series', $series)->delete();
        } else {
            DB::table('wdb_dan_disabled_series')->insertOrIgnore(['series' => $series, 'created_at' => now(), 'updated_at' => now()]);
        }

        return back();
    }
}
