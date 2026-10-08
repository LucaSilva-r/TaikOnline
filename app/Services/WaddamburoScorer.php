<?php

namespace App\Services;

use App\Models\WdbPlay;
use Illuminate\Database\Eloquent\Collection;
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
        $plays->loadMissing('chart:id,notes,source');
        $plays = $plays->filter(fn (WdbPlay $play): bool => $play->chart?->notes !== null)->keyBy('id');
        if ($plays->isEmpty()) {
            return $summary;
        }

        $notes = [];
        $input = $plays->map(fn (WdbPlay $play): string => json_encode([
            'id' => $play->id,
            'notes' => $notes[$play->wdb_chart_id] ??= base64_encode((string) $play->chart->notes),
            'source' => $play->chart->source,
            'options' => (int) $play->options,
            'seed' => $play->seed === null ? null : (int) $play->seed,
            'replay' => base64_encode((string) $play->replay),
        ], JSON_THROW_ON_ERROR))->implode("\n")."\n";
        $run = Process::input($input)->timeout(600)->run([$scorer, '--rescore']);
        if ($run->failed()) {
            Log::error('Waddamburo scorer failed.', ['exit' => $run->exitCode(), 'error' => $run->errorOutput()]);

            return [...$summary, 'failed' => $plays->count()];
        }

        $changed = [];
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
            $changed[(int) $play->baid] = true;
            $summary['scored']++;
        }

        return [...$summary, 'players' => array_keys($changed)];
    }
}
