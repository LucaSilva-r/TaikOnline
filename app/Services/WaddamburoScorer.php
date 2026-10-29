<?php

namespace App\Services;

use App\Models\WdbChart;
use App\Models\WdbPlay;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Scores Waddamburo plays from their replays with the game's own rules (the Waddamburo binary's
 * --rescore mode, so the server never keeps a second copy of them): the server's numbers replace
 * the client's, and the engine's judgement timeline is kept beside them (WdbPlay::judgementTimeline).
 * A rules change is a new binary plus `app:rescore-waddamburo-plays --all`.
 */
class WaddamburoScorer
{
    /** The numbers the scorer gives back, as wdb_plays keeps them. */
    private const RESULT = ['course', 'score', 'great', 'good', 'miss', 'max_combo', 'rolls', 'gauge', 'cleared', 'scoring_version'];

    /** Plays per scorer run: each result carries its play's judgement timeline, so a run's output grows with it. */
    private const BATCH = 50;

    /**
     * Rescores the plays whose chart has notes (the rest wait for their chart). Returns the players whose
     * plays scored, how many scored or failed, and every score that moved.
     *
     * @param  Collection<int, WdbPlay>  $plays
     * @return array{players: list<int>, scored: int, failed: int, changes: list<array{play: string, baid: int, from: int, to: int}>}
     */
    public function rescore(Collection $plays): array
    {
        $summary = ['players' => [], 'scored' => 0, 'failed' => 0, 'changes' => []];
        $scorer = config('services.waddamburo.scorer');
        if (! is_string($scorer) || $scorer === '' || ! is_executable($scorer)) {
            Log::warning('WADDAMBURO_SCORER is not set to an executable: plays are not rescored.');

            return $summary;
        }
        $plays->loadMissing('chart:id,notes,source,roll_max');
        $plays = $plays->filter(fn (WdbPlay $play): bool => $play->chart?->notes !== null)->keyBy('id');
        $changed = [];
        $rollMax = [];
        foreach ($plays->chunk(self::BATCH) as $batch) {
            $this->scoreBatch($scorer, $batch, $summary, $changed, $rollMax);
        }

        foreach ($rollMax as $chartId => $max) {
            WdbChart::query()->whereKey($chartId)->update(['roll_max' => $max]);
        }

        return [...$summary, 'players' => array_keys($changed)];
    }

    /**
     * One scorer run over a batch, its results applied to the plays.
     *
     * @param  Collection<string, WdbPlay>  $plays
     * @param  array{players: list<int>, scored: int, failed: int, changes: list<array{play: string, baid: int, from: int, to: int}>}  $summary
     * @param  array<int, true>  $changed
     * @param  array<int, int>  $rollMax
     */
    private function scoreBatch(string $scorer, Collection $plays, array &$summary, array &$changed, array &$rollMax): void
    {
        $notes = [];
        $input = $plays->map(fn (WdbPlay $play): string => $this->line($play, $notes[$play->wdb_chart_id] ??= base64_encode((string) $play->chart->notes)))
            ->implode("\n")."\n";
        $run = Process::input($input)->timeout(600)->run([$scorer, '--rescore']);
        if ($run->failed()) {
            Log::error('Waddamburo scorer failed.', ['exit' => $run->exitCode(), 'error' => $run->errorOutput()]);
            $summary['failed'] += $plays->count();

            return;
        }

        foreach (preg_split('/\R/', trim($run->output()), flags: PREG_SPLIT_NO_EMPTY) as $line) {
            $result = json_decode($line, true);
            $play = is_array($result) ? $plays->get($result['id'] ?? null) : null;
            if ($play === null) {
                continue;
            }
            if (isset($result['error']) || array_diff(self::RESULT, array_keys($result)) !== []) {
                Log::warning('Waddamburo play did not score from its replay.', ['play' => $play->id, 'error' => $result['error'] ?? 'incomplete result']);
                $summary['failed']++;

                continue;
            }
            $scored = array_intersect_key($result, array_flip(self::RESULT));
            $claimed = $play->only(self::RESULT);
            // Same rules on both sides should give the same numbers: a difference is a cheat or an engine bug.
            if ($play->rescored_at === null && (int) $claimed['scoring_version'] === (int) $scored['scoring_version']
                && array_map('intval', $claimed) != array_map('intval', $scored)) {
                Log::warning('Waddamburo play differs from its replay.', ['play' => $play->id, 'baid' => $play->baid,
                    'claimed' => $claimed, 'scored' => $scored]);
            }
            if ((int) $claimed['score'] !== (int) $scored['score']) {
                $summary['changes'][] = ['play' => $play->id, 'baid' => (int) $play->baid, 'from' => (int) $claimed['score'], 'to' => (int) $scored['score']];
            }
            $play->update([
                ...$scored,
                'rescored_at' => now(),
                'timeline' => isset($result['timeline']) ? gzencode(json_encode($result['timeline'], JSON_THROW_ON_ERROR), 9) : null,
            ]);
            // The chart's own (the same for all its plays): its drumrolls' most hits.
            if (isset($result['roll_max']) && $play->chart->roll_max !== (int) $result['roll_max']) {
                $rollMax[$play->wdb_chart_id] = (int) $result['roll_max'];
            }
            $changed[(int) $play->baid] = true;
            $summary['scored']++;
        }
    }

    /** One play as the binary reads it on stdin (its chart's stored notes given base64, shared across a batch). */
    private function line(WdbPlay $play, string $notes): string
    {
        return json_encode([
            'id' => $play->id,
            'notes' => $notes,
            'source' => $play->chart->source,
            'options' => (int) $play->options,
            'seed' => $play->seed === null ? null : (int) $play->seed,
            'replay' => base64_encode((string) $play->replay),
        ], JSON_THROW_ON_ERROR);
    }
}
