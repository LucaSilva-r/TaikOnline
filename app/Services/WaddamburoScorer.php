<?php

namespace App\Services;

use App\Models\WdbChart;
use App\Models\WdbPlay;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Scores Waddamburo plays from their replays with the game's own rules (the Waddamburo binary's
 * --rescore mode, so the server never keeps a second copy of them): the server's numbers replace
 * the client's. A rules change is a new binary plus `app:rescore-waddamburo-plays --all`.
 */
class WaddamburoScorer
{
    /** The numbers the scorer gives back, as wdb_plays keeps them. */
    private const RESULT = ['course', 'score', 'great', 'good', 'miss', 'max_combo', 'rolls', 'gauge', 'cleared', 'scoring_version'];

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
        if ($plays->isEmpty()) {
            return $summary;
        }

        $notes = [];
        $input = $plays->map(fn (WdbPlay $play): string => $this->line($play, $notes[$play->wdb_chart_id] ??= base64_encode((string) $play->chart->notes)))
            ->implode("\n")."\n";
        $run = Process::input($input)->timeout(600)->run([$scorer, '--rescore']);
        if ($run->failed()) {
            Log::error('Waddamburo scorer failed.', ['exit' => $run->exitCode(), 'error' => $run->errorOutput()]);

            return [...$summary, 'failed' => $plays->count()];
        }

        $changed = [];
        $rollMax = [];
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
            $play->update([...$scored, 'rescored_at' => now()]);
            // The chart's own (the same for all its plays): its drumrolls' most hits.
            if (isset($result['roll_max']) && $play->chart->roll_max !== (int) $result['roll_max']) {
                $rollMax[$play->wdb_chart_id] = (int) $result['roll_max'];
            }
            $changed[(int) $play->baid] = true;
            $summary['scored']++;
        }

        foreach ($rollMax as $chartId => $max) {
            WdbChart::query()->whereKey($chartId)->update(['roll_max' => $max]);
        }

        return [...$summary, 'players' => array_keys($changed)];
    }

    /**
     * A play's whole judgement timeline as the engine plays it (the binary's --inspect mode: every note's
     * result and offset, every input and what it hit, the roll hits), cached per play and scoring version.
     * Null when the binary is not set, the chart has no notes yet, or the play does not play.
     *
     * @return array<string, mixed>|null
     */
    public function inspect(WdbPlay $play): ?array
    {
        $scorer = config('services.waddamburo.scorer');
        if (! is_string($scorer) || $scorer === '' || ! is_executable($scorer)) {
            return null;
        }
        $play->loadMissing('chart:id,notes,source');
        if ($play->chart?->notes === null) {
            return null;
        }

        // ponytail: one cache entry per play (~100 KB of JSON); a table when inspections get heavy use.
        return Cache::remember("wdb-inspect:{$play->id}:{$play->scoring_version}", now()->addWeek(), function () use ($play, $scorer): ?array {
            $run = Process::input($this->line($play, base64_encode((string) $play->chart->notes))."\n")->timeout(60)->run([$scorer, '--inspect']);
            $result = $run->successful() ? json_decode(trim($run->output()), true) : null;
            if (! is_array($result) || isset($result['error'])) {
                Log::warning('Waddamburo play could not be inspected.', ['play' => $play->id, 'error' => $result['error'] ?? $run->errorOutput()]);

                return null;
            }

            return $result;
        });
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
