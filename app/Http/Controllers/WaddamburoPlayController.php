<?php

namespace App\Http\Controllers;

use App\Models\Sanction;
use App\Models\WdbPlay;
use App\Services\ModerationService;
use App\Services\PlayerRankAggregateService;
use App\Services\WaddamburoRankAggregateService;
use App\Services\WaddamburoScorer;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One Waddamburo play's page: its result for everyone (with a few numbers from the engine's timeline, such
 * as roll speed), and for staff the moderation panel with the whole timeline (docs/features/moderation.md).
 */
class WaddamburoPlayController extends Controller
{
    public function show(Request $request, string $id, WaddamburoScorer $scorer): Response
    {
        abort_unless((bool) $request->attributes->get('taikoVersionIsWaddamburo', false), 404);
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $id) === 1, 404);
        $viewer = $request->user();
        $isStaff = $viewer?->isStaff() === true;
        $viewerBaid = $viewer?->player?->baid === null ? null : (int) $viewer->player->baid;
        // Staff see every play; everyone else what the boards show them.
        $play = WdbPlay::query()->when(! $isStaff, fn ($query) => $query->visibleTo($viewerBaid))
            ->with(['chart', 'player.user'])->findOrFail($id);
        $chart = $play->chart;
        $user = $play->player?->user;
        $shinuchi = ((int) $play->options & WdbPlay::SHINUCHI) !== 0;
        $visibleBests = fn (QueryBuilder $query) => $query->where('wdb_plays.wdb_chart_id', $chart->id);

        return Inertia::render('WaddamburoPlay', [
            'play' => [
                'id' => $play->id,
                'score' => (int) $play->score,
                'great' => (int) $play->great,
                'good' => (int) $play->good,
                'miss' => (int) $play->miss,
                'max_combo' => (int) $play->max_combo,
                'rolls' => (int) $play->rolls,
                'roll_max' => $chart->roll_max,
                'gauge' => (int) $play->gauge,
                'cleared' => (bool) $play->cleared,
                'crown' => self::crown($play),
                'precision' => PlayerRankAggregateService::precision((int) $play->great, (int) $play->good, (int) $play->miss),
                'level' => (int) $play->course + 1,
                'options' => WdbPlay::optionLabels((int) $play->options),
                'played_at' => $play->played_at?->toIso8601String(),
                'audio_offset_ms' => $play->audio_offset_ms,
                'input_offset_ms' => $play->input_offset_ms,
                'scored' => $play->rescored_at !== null,
                // Where this play's score would stand on its chart's board (each player's best).
                'placement' => $play->rescored_at === null ? null : DB::query()
                    ->fromSub(WaddamburoRankAggregateService::bests($visibleBests, $shinuchi, $viewerBaid), 'bests')
                    ->where('best_score', '>', $play->score)->count() + 1,
            ],
            'player' => [
                'user_id' => $user?->id,
                'name' => (string) ($user?->name ?? $play->player?->mydon_name ?? 'Unknown'),
                'avatar' => $user?->avatar,
            ],
            'chart' => [
                'song_id' => ($songId = $chart->songs()->min('wdb_songs.id')) === null ? null : (int) $songId,
                'title' => $chart->title ?? $chart->title_en ?? 'Unknown chart',
                'title_en' => $chart->title_en,
                'difficulty' => $chart->difficulty,
                'ranked' => $chart->ranked_at !== null,
                'external_url' => $chart->osuUrl(),
            ],
            'otherPlays' => WdbPlay::query()->where('baid', $play->baid)->where('wdb_chart_id', $chart->id)
                ->whereKeyNot($play->id)->when(! $isStaff, fn ($query) => $query->visibleTo($viewerBaid))
                ->orderByDesc('score')->limit(10)->get()
                ->map(fn (WdbPlay $other): array => [
                    'id' => $other->id,
                    'score' => (int) $other->score,
                    'crown' => self::crown($other),
                    'options' => WdbPlay::optionLabels((int) $other->options),
                    'played_at' => $other->played_at?->toIso8601String(),
                    'hidden' => $other->hidden_at !== null,
                ])->all(),
            'stats' => Inertia::defer(fn (): ?array => self::publicStats($scorer->inspect($play))),
            'moderation' => $isStaff ? [
                'baid' => (int) $play->baid,
                'hidden_at' => $play->hidden_at?->toIso8601String(),
                'hidden_reason' => $play->hidden_reason,
                'sanctions' => Sanction::query()->active()->where('baid', $play->baid)->pluck('type')->map->value->unique()->values()->all(),
                'can_act' => $play->player !== null && ModerationService::canActOn($viewer, $play->player),
            ] : null,
            // The engine's whole timeline: staff only (what reviewers look at stays with them).
            'inspection' => $isStaff ? Inertia::defer(fn (): ?array => $scorer->inspect($play)) : null,
        ]);
    }

    /**
     * What everyone sees of a play's timeline: its mean hit offset, how many hits were early or late, and
     * its drumroll speed (hits per second over each roll's hits; the average across rolls and the fastest).
     *
     * @param  array<string, mixed>|null  $inspection
     * @return array{mean_offset: ?float, early: int, late: int, roll_speed: ?array{average: float, peak: float}}|null
     */
    public static function publicStats(?array $inspection): ?array
    {
        if ($inspection === null) {
            return null;
        }
        $offsets = collect($inspection['notes'] ?? [])
            ->filter(fn (array $note): bool => isset($note['offset']) && ($note['result'] ?? 'miss') !== 'miss')
            ->pluck('offset');
        [$hits, $span, $peak] = [0, 0.0, 0.0];
        foreach ($inspection['long_notes'] ?? [] as $roll) {
            $times = $roll['hits'] ?? [];
            $seconds = count($times) < 2 ? 0 : (end($times) - $times[0]) / 1000;
            if ($seconds > 0) {
                $hits += count($times) - 1;
                $span += $seconds;
                $peak = max($peak, (count($times) - 1) / $seconds);
            }
        }

        return [
            'mean_offset' => $offsets->isEmpty() ? null : round($offsets->avg(), 1),
            'early' => $offsets->filter(fn (float|int $offset): bool => $offset < 0)->count(),
            'late' => $offsets->filter(fn (float|int $offset): bool => $offset > 0)->count(),
            'roll_speed' => $span > 0 ? ['average' => round($hits / $span, 1), 'peak' => round($peak, 1)] : null,
        ];
    }

    private static function crown(WdbPlay $play): int
    {
        return match (true) {
            $play->cleared && $play->miss === 0 && $play->good === 0 => 3,
            $play->cleared && $play->miss === 0 => 2,
            (bool) $play->cleared => 1,
            default => 0,
        };
    }
}
