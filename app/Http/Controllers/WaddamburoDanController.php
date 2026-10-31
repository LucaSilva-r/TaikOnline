<?php

namespace App\Http\Controllers;

use App\Enums\SanctionType;
use App\Http\Middleware\AuthenticateWaddamburo;
use App\Models\Player;
use App\Models\Sanction;
use App\Models\WdbChart;
use App\Models\WdbDanCourse;
use App\Models\WdbDanPlay;
use App\Services\WaddamburoDanScorer;
use App\Services\WaddamburoSongs;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $disabled = WdbDanCourse::disabledSeries();

        $series = [];
        foreach (WdbDanCourse::SERIES as $id => $name) {
            if (! $courses->has($id) || in_array($id, $disabled, true)) {
                continue;
            }
            $charts = WdbDanCourse::officialCharts($songKeys, $id);
            $series[] = [
                'id' => $id,
                'name' => $name,
                'courses' => $courses[$id]->map(fn (WdbDanCourse $course): array => [
                    'level' => $course->level,
                    'name' => $course->name,
                    'hash' => $course->content_hash,
                    'requirements' => $course->requirements,
                    'rules' => WdbDanCourse::isNijiiro($id) ? 'nijiiro' : null,
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

    /**
     * Uploads dan plays. A play counts only with the course's own charts, named by hash for each of its songs;
     * the server then scores it itself, so the numbers sent are only compared.
     */
    public function storePlays(Request $request, WaddamburoDanScorer $scorer): JsonResponse
    {
        $cabinet = $request->attributes->get(AuthenticateWaddamburo::CABINET) === true;
        $validated = $request->validate([
            'plays' => ['required', 'array', 'min:1', 'max:20'],
            'plays.*.id' => ['required', 'uuid'],
            'plays.*.baid' => [$cabinet ? 'required' : 'nullable', 'integer'],
            'plays.*.course_hash' => ['required', 'string', 'size:64'],
            'plays.*.result_level' => ['required', 'integer', 'between:0,2'],
            'plays.*.score' => ['required', 'integer', 'min:0'],
            'plays.*.great' => ['required', 'integer', 'min:0'],
            'plays.*.good' => ['required', 'integer', 'min:0'],
            'plays.*.miss' => ['required', 'integer', 'min:0'],
            'plays.*.max_combo' => ['required', 'integer', 'min:0'],
            'plays.*.rolls' => ['required', 'integer', 'min:0'],
            'plays.*.gauge_percent' => ['required', 'integer', 'between:0,100'],
            'plays.*.songs_played' => ['required', 'integer', 'between:1,3'],
            'plays.*.scoring_version' => ['required', 'integer', 'min:1'],
            'plays.*.engine_version' => ['present', 'nullable', 'string', 'max:64'],
            'plays.*.audio_offset_ms' => ['nullable', 'integer', 'between:-5000,5000'],
            'plays.*.input_offset_ms' => ['nullable', 'integer', 'between:-5000,5000'],
            'plays.*.played_at' => ['required', 'date'],
            'plays.*.songs' => ['required', 'array', 'size:3'],
            'plays.*.songs.*.sha256' => ['required', 'string', 'regex:/\A[0-9a-f]{64}\z/'],
            'plays.*.songs.*.replay' => ['present', 'nullable', 'string', 'max:2000000'],
        ]);
        $ownBaid = $cabinet ? null : $request->user()->player?->baid;
        abort_if(! $cabinet && $ownBaid === null, 403, 'Your account does not have a Banapass yet.');

        $accepted = [];
        $created = new Collection;
        foreach ($validated['plays'] as $play) {
            $baid = $ownBaid ?? (int) $play['baid'];
            if ($ownBaid !== null && isset($play['baid']) && (int) $play['baid'] !== $ownBaid) {
                continue;
            }
            if ($cabinet && ! Player::query()->whereKey($baid)->exists()) {
                continue;
            }
            // Failed runs aren't kept: accepted, so a client still sending them stops.
            if ((int) $play['result_level'] === 0) {
                $accepted[] = $play['id'];

                continue;
            }
            $course = WdbDanCourse::query()->with('songs')->where('content_hash', $play['course_hash'])
                ->whereNotIn('series', WdbDanCourse::disabledSeries())->first();
            if ($course === null || $course->songs->count() !== 3) {
                continue;
            }
            $allowed = WdbDanCourse::officialCharts($course->songs->pluck('song_key'), $course->series);
            $chartIds = [];
            foreach ($course->songs->values() as $index => $song) {
                $sha = $play['songs'][$index]['sha256'];
                if (! ($allowed[$song->song_key."\0".$song->course] ?? collect())->contains('sha256', $sha)) {
                    continue 2;
                }
                $chartIds[$index] = WdbChart::query()->where('sha256', $sha)->value('id');
            }
            $replays = array_map(fn (array $song) => base64_decode((string) $song['replay'], true), $play['songs']);
            if (in_array(false, $replays, true)) {
                continue;
            }
            $stored = DB::transaction(function () use ($play, $baid, $chartIds, $replays): WdbDanPlay {
                $stored = WdbDanPlay::query()->firstOrCreate(['id' => $play['id']], [
                    'baid' => $baid,
                    'content_hash' => $play['course_hash'],
                    'result_level' => $play['result_level'],
                    'score' => $play['score'],
                    'great' => $play['great'],
                    'good' => $play['good'],
                    'miss' => $play['miss'],
                    'max_combo' => $play['max_combo'],
                    'rolls' => $play['rolls'],
                    'gauge_percent' => $play['gauge_percent'],
                    'songs_played' => $play['songs_played'],
                    'scoring_version' => $play['scoring_version'],
                    'engine_version' => (string) ($play['engine_version'] ?? ''),
                    'audio_offset_ms' => $play['audio_offset_ms'] ?? null,
                    'input_offset_ms' => $play['input_offset_ms'] ?? null,
                    'played_at' => $play['played_at'],
                    // Played while restricted: kept (evidence), never counted, even after the ban ends.
                    ...(Sanction::activeFor($baid, SanctionType::Ban) !== null
                        ? ['hidden_at' => now(), 'hidden_reason' => 'Played while restricted'] : []),
                ]);
                if ($stored->wasRecentlyCreated) {
                    foreach ($chartIds as $position => $chartId) {
                        $stored->songs()->create(['position' => $position, 'wdb_chart_id' => $chartId, 'replay' => $replays[$position]]);
                    }
                }

                return $stored;
            });
            if ($stored->wasRecentlyCreated) {
                $created->push($stored);
            }
            $accepted[] = $play['id'];
        }
        $scorer->rescore($created);

        return response()->json(['accepted' => $accepted]);
    }
}
