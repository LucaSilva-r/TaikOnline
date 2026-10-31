<?php

namespace App\Services;

use App\Models\WdbDanCourse;
use App\Models\WdbDanPlay;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Scores dan plays with the game's own rules (the Waddamburo binary's --rescore-dan): the course as one run from
 * its songs' replays, against the requirements and charts held here. The result level it gives is the badge,
 * and the client's numbers are only compared with it.
 */
class WaddamburoDanScorer
{
    private const NUMBERS = ['result_level', 'score', 'great', 'good', 'miss', 'max_combo', 'rolls', 'gauge_percent', 'songs_played'];

    /** @param  Collection<int, WdbDanPlay>  $plays */
    public function rescore(Collection $plays): void
    {
        $scorer = config('services.waddamburo.scorer');
        if ($plays->isEmpty() || ! is_string($scorer) || $scorer === '' || ! is_executable($scorer)) {
            return;
        }
        $plays->loadMissing('songs.chart:id,notes,source');
        $plays = $plays->keyBy('id');
        $courses = WdbDanCourse::query()->with('songs')->whereIn('content_hash', $plays->pluck('content_hash'))->get()->keyBy('content_hash');
        $input = $plays->filter(fn (WdbDanPlay $play): bool => $courses->has($play->content_hash)
            && $play->songs->every(fn ($song): bool => $song->chart?->notes !== null))
            ->map(fn (WdbDanPlay $play): string => $this->line($play, $courses[$play->content_hash]))->implode("\n");
        if ($input === '') {
            return;
        }
        $run = Process::input($input."\n")->timeout(600)->run([$scorer, '--rescore-dan']);
        if ($run->failed()) {
            Log::error('Waddamburo dan scorer failed.', ['exit' => $run->exitCode(), 'error' => $run->errorOutput()]);

            return;
        }
        foreach (preg_split('/\R/', trim($run->output()), flags: PREG_SPLIT_NO_EMPTY) as $line) {
            $result = json_decode($line, true);
            $play = is_array($result) ? $plays->get($result['id'] ?? null) : null;
            if ($play !== null) {
                $this->apply($play, $result);
            }
        }
    }

    /** @param  array<string, mixed>  $result */
    private function apply(WdbDanPlay $play, array $result): void
    {
        if (isset($result['error']) || array_diff(self::NUMBERS, array_keys($result)) !== []) {
            Log::warning('Waddamburo dan play did not score from its replays.', ['play' => $play->id, 'error' => $result['error'] ?? null]);

            return;
        }
        $scored = array_intersect_key($result, array_flip(self::NUMBERS));
        // Same rules on both sides should give the same numbers: a difference is a cheat or an engine bug.
        if ($play->rescored_at === null && (int) $play->scoring_version === (int) ($result['scoring_version'] ?? 0)
            && array_map('intval', $play->only(self::NUMBERS)) != array_map('intval', $scored)) {
            Log::warning('Waddamburo dan play differs from its replays.', ['play' => $play->id, 'baid' => $play->baid,
                'claimed' => $play->only(self::NUMBERS), 'scored' => $scored]);
        }
        $play->update([...$scored, 'scoring_version' => $result['scoring_version'] ?? $play->scoring_version, 'rescored_at' => now(),
            'progress' => isset($result['progress']) ? gzencode(json_encode($result['progress'], JSON_THROW_ON_ERROR)) : null]);
        foreach ($play->songs as $index => $song) {
            $song->update([...array_intersect_key($result['songs'][$index] ?? [], array_flip(['great', 'good', 'miss', 'rolls'])),
                'timeline' => isset($result['timelines'][$index]) ? gzencode(json_encode($result['timelines'][$index], JSON_THROW_ON_ERROR)) : null]);
        }
    }

    private function line(WdbDanPlay $play, WdbDanCourse $course): string
    {
        return json_encode([
            'id' => $play->id,
            'requirements' => $course->requirements,
            'songs' => $play->songs->map(fn ($song): array => [
                'notes' => base64_encode((string) $song->chart->notes),
                'source' => $song->chart->source,
                'note_count' => (int) ($course->songs[$song->position]->notes ?? 0),
                'replay' => base64_encode((string) $song->replay),
            ])->values()->all(),
        ], JSON_THROW_ON_ERROR);
    }
}
