<?php

namespace App\Http\Controllers;

use App\Enums\PlayReportReason;
use App\Models\WdbDanCourse;
use App\Models\WdbDanPlay;
use App\Models\WdbDanPlayReport;
use App\Services\ModerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A dan run's page, open to everyone: other players catch cheated runs by watching them. Each song is a normal
 * replay of its chart, beside the course's requirements as they stood at that moment of the run.
 */
class WaddamburoDanPlayController extends Controller
{
    public function show(Request $request, string $id): Response
    {
        abort_unless((bool) $request->attributes->get('taikoVersionIsWaddamburo', false), 404);
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $id) === 1, 404);
        $viewer = $request->user();
        $isStaff = $viewer?->isStaff() === true;
        $viewerBaid = $viewer?->player?->baid === null ? null : (int) $viewer->player->baid;
        $play = WdbDanPlay::query()->when(! $isStaff, fn ($query) => $query->visibleTo($viewerBaid))
            ->with(['songs.chart:id,title,title_en,course,level', 'player.user'])->findOrFail($id);
        $course = WdbDanCourse::query()->where('content_hash', $play->content_hash)->firstOrFail();
        $user = $play->player?->user;

        return Inertia::render('WaddamburoDanPlay', [
            'play' => [
                'id' => $play->id,
                'result_level' => (int) $play->result_level,
                'score' => (int) $play->score,
                'great' => (int) $play->great,
                'good' => (int) $play->good,
                'miss' => (int) $play->miss,
                'max_combo' => (int) $play->max_combo,
                'rolls' => (int) $play->rolls,
                'gauge_percent' => (int) $play->gauge_percent,
                'songs_played' => (int) $play->songs_played,
                'played_at' => $play->played_at?->toIso8601String(),
                'scored' => $play->rescored_at !== null,
            ],
            'course' => [
                'name' => $course->name,
                'label' => WdbDanCourse::RANK_LABELS[$course->name] ?? WdbDanCourse::challengeTitle($course->name),
                'requirements' => $course->requirements,
                // What a Goods or combo requirement can still reach, as the scorer works it out.
                'notes' => (int) $course->songs()->sum('notes'),
                'series' => WdbDanCourse::query()->where('content_hash', $play->content_hash)->pluck('series')
                    ->map(fn (string $series): string => WdbDanCourse::SERIES[$series] ?? $series)->values(),
            ],
            'player' => [
                'user_id' => $user?->id,
                'name' => (string) ($user?->name ?? $play->player?->mydon_name ?? 'Unknown'),
                'avatar' => $user?->avatar,
            ],
            'songs' => $play->songs->map(fn ($song): array => [
                'title' => $song->chart?->title_en ?? $song->chart?->title ?? 'Unknown song',
                'course' => $song->chart?->course === null ? null : (int) $song->chart->course,
                'level' => $song->chart?->level === null ? null : (int) $song->chart->level,
                'great' => (int) $song->great,
                'good' => (int) $song->good,
                'miss' => (int) $song->miss,
                'rolls' => (int) $song->rolls,
            ])->values(),
            // The numbers as the run goes: song, ms, gauge %, goods, oks, bads, combo, rolls, score.
            'progress' => Inertia::defer(fn (): ?array => self::decoded($play->progress)),
            // What the replay player draws of each song; what each input hit stays with staff.
            'replays' => Inertia::defer(fn (): array => $play->songs->map(function ($song): ?array {
                $timeline = self::decoded($song->timeline);

                return $timeline === null ? null : [
                    ...array_intersect_key($timeline, array_flip(['duration', 'notes', 'long_notes', 'bars', 'gogo', 'branches'])),
                    'inputs' => array_map(fn (array $input): array => ['t' => $input['t'], 'action' => $input['action']], $timeline['inputs'] ?? []),
                ];
            })->values()->all()),
            // What everyone sees of each song's timeline, as on a song play's page.
            'stats' => Inertia::defer(fn (): array => $play->songs
                ->map(fn ($song): ?array => WaddamburoPlayController::publicStats(self::decoded($song->timeline)))->values()->all()),
            // The engine's whole timeline: staff only (what reviewers look at stays with them).
            'inspections' => $isStaff ? Inertia::defer(fn (): array => $play->songs
                ->map(fn ($song): ?array => self::decoded($song->timeline))->values()->all()) : null,
            'report' => $viewerBaid === null || $viewerBaid === (int) $play->baid ? null : [
                'reasons' => collect(PlayReportReason::cases())->map(fn (PlayReportReason $reason): array => ['value' => $reason->value, 'label' => $reason->label()])->all(),
                'reported' => WdbDanPlayReport::query()->where('wdb_dan_play_id', $play->id)->where('reporter_id', $viewer->id)->exists(),
            ],
            'moderation' => $isStaff ? [
                'baid' => (int) $play->baid,
                'hidden_at' => $play->hidden_at?->toIso8601String(),
                'hidden_reason' => $play->hidden_reason,
                'open_reports' => WdbDanPlayReport::query()->open()->where('wdb_dan_play_id', $play->id)->count(),
            ] : null,
        ]);
    }

    /** A logged-in player reports a run from its page. */
    public function report(Request $request, string $id, ModerationService $moderation): RedirectResponse
    {
        abort_unless((bool) $request->attributes->get('taikoVersionIsWaddamburo', false), 404);
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $id) === 1, 404);
        $validated = $request->validate([
            'reason' => ['required', Rule::enum(PlayReportReason::class)],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);
        $filed = $moderation->reportDan($request->user(), WdbDanPlay::query()->findOrFail($id),
            PlayReportReason::from($validated['reason']), $validated['comment'] ?? null);
        Inertia::flash('toast', ['type' => 'success', 'message' => $filed ? __('Thanks, the report was sent to the moderators.') : __('You already reported this run.')]);

        return back();
    }

    private static function decoded(mixed $stored): ?array
    {
        $json = $stored === null ? false : @gzdecode((string) $stored);

        return $json === false ? null : json_decode($json, true);
    }
}
