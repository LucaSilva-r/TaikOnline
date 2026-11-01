<?php

namespace App\Services;

use App\Enums\UploadWarningKind;
use App\Models\WdbPlay;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Runs the private analyzer (WADDAMBURO_ANALYZER, any executable; none there, no analysis) over scored plays:
 * JSON lines in, one per play, and {id, flag: none|review|strong, analysis: {...}} or {id, error} out. What it
 * looks for stays out of this repository: the flag sends a play to the upload warnings page, nothing is
 * sanctioned and the player is not told. See docs/features/moderation.md.
 */
class WaddamburoAnalyzer
{
    private const FLAGS = ['none', 'review', 'strong'];

    /** Plays per run: each carries the timelines of the chart's best plays. */
    private const BATCH = 10;

    /** The other players' best plays on the chart sent with each play, to compare it with. */
    private const COMPARED_PLAYS = 20;

    private static function path(): ?string
    {
        $analyzer = config('services.waddamburo.analyzer');

        return is_string($analyzer) && $analyzer !== '' && is_executable($analyzer) ? $analyzer : null;
    }

    public static function isAvailable(): bool
    {
        return self::path() !== null;
    }

    /**
     * @param  Collection<int, WdbPlay>  $plays
     * @return array{analyzed: int, failed: int, flagged: int}
     */
    public function analyze(Collection $plays): array
    {
        $summary = ['analyzed' => 0, 'failed' => 0, 'flagged' => 0];
        $analyzer = self::path();
        if ($analyzer === null) {
            return $summary;
        }
        foreach ($plays->filter(fn (WdbPlay $play): bool => $play->timeline !== null)->keyBy('id')->chunk(self::BATCH) as $batch) {
            $run = Process::input($batch->map(fn (WdbPlay $play): string => $this->line($play))->implode("\n")."\n")
                ->timeout(600)->path(sys_get_temp_dir())->run([$analyzer]);
            if ($run->failed()) {
                Log::error('Waddamburo analyzer failed.', ['exit' => $run->exitCode(), 'error' => $run->errorOutput()]);
                $summary['failed'] += $batch->count();

                continue;
            }
            foreach (preg_split('/\R/', trim($run->output()), flags: PREG_SPLIT_NO_EMPTY) as $line) {
                $result = json_decode($line, true);
                $play = is_array($result) ? $batch->get($result['id'] ?? null) : null;
                if ($play === null) {
                    continue;
                }
                if (! in_array($result['flag'] ?? null, self::FLAGS, true)) {
                    Log::warning('Waddamburo analyzer gave no flag.', ['play' => $play->id, 'error' => $result['error'] ?? null]);
                    $summary['failed']++;

                    continue;
                }
                $this->apply($play, $result['flag'], $result['analysis'] ?? null);
                $summary['analyzed']++;
                $summary['flagged'] += $result['flag'] === 'none' ? 0 : 1;
            }
        }

        return $summary;
    }

    /** @param  array<string, mixed>|null  $analysis */
    private function apply(WdbPlay $play, string $flag, ?array $analysis): void
    {
        $isNewFlag = $flag !== 'none' && $play->analysis_flag !== $flag;
        $play->update(['analysis_flag' => $flag, 'analysis' => $analysis, 'analyzed_at' => now()]);
        if ($isNewFlag) {
            WaddamburoUploadWarnings::record((int) $play->baid, UploadWarningKind::AnalyzerFlag, $play->id, "Flag: {$flag}");
        }
    }

    /** One play: its numbers, gzipped timeline and replay (base64), and the same for the plays to compare it with. */
    private function line(WdbPlay $play): string
    {
        return json_encode([
            'id' => $play->id,
            'baid' => (int) $play->baid,
            'chart' => (int) $play->wdb_chart_id,
            'options' => (int) $play->options,
            'seed' => $play->seed === null ? null : (int) $play->seed,
            'engine_version' => $play->engine_version,
            'score' => (int) $play->score,
            'timeline' => base64_encode((string) $play->timeline),
            'replay' => base64_encode((string) $play->replay),
            'others' => WdbPlay::query()->where('wdb_chart_id', $play->wdb_chart_id)->where('baid', '!=', $play->baid)
                ->whereNull('hidden_at')->whereNotNull('timeline')->orderByDesc('score')->limit(self::COMPARED_PLAYS)
                ->get(['id', 'baid', 'timeline'])
                ->map(fn (WdbPlay $other): array => [
                    'id' => $other->id,
                    'baid' => (int) $other->baid,
                    'timeline' => base64_encode((string) $other->timeline),
                ])->all(),
        ], JSON_THROW_ON_ERROR);
    }
}
