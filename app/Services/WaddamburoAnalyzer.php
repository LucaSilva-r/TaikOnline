<?php

namespace App\Services;

use App\Enums\UploadWarningKind;
use App\Models\WdbDanPlay;
use App\Models\WdbDanPlaySong;
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

    /** Runs per process: each carries up to three songs' worth. */
    private const DAN_BATCH = 4;

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
        return $this->run(
            $plays->filter(fn (WdbPlay $play): bool => $play->timeline !== null),
            self::BATCH,
            fn (WdbPlay $play): array => [
                'options' => (int) $play->options,
                'seed' => $play->seed === null ? null : (int) $play->seed,
                ...$this->song((int) $play->wdb_chart_id, (int) $play->baid, $play->timeline, $play->replay),
            ],
        );
    }

    /**
     * Dan runs: one line per run, with a `songs` list of the songs it reached instead of the single play's fields.
     *
     * @param  Collection<int, WdbDanPlay>  $plays
     * @return array{analyzed: int, failed: int, flagged: int}
     */
    public function analyzeDan(Collection $plays): array
    {
        $plays->loadMissing('songs');

        return $this->run(
            $plays->filter(fn (WdbDanPlay $play): bool => $play->songs->contains(fn (WdbDanPlaySong $song): bool => $song->timeline !== null)),
            self::DAN_BATCH,
            fn (WdbDanPlay $play): array => [
                'songs' => $play->songs->filter(fn (WdbDanPlaySong $song): bool => $song->timeline !== null)
                    ->map(fn (WdbDanPlaySong $song): array => [
                        'position' => (int) $song->position,
                        ...$this->song((int) $song->wdb_chart_id, (int) $play->baid, $song->timeline, $song->replay),
                    ])->values()->all(),
            ],
        );
    }

    /**
     * @param  Collection<int, WdbPlay|WdbDanPlay>  $plays
     * @param  callable(WdbPlay|WdbDanPlay): array<string, mixed>  $fields
     * @return array{analyzed: int, failed: int, flagged: int}
     */
    private function run(Collection $plays, int $batchSize, callable $fields): array
    {
        $summary = ['analyzed' => 0, 'failed' => 0, 'flagged' => 0];
        $analyzer = self::path();
        if ($analyzer === null) {
            return $summary;
        }
        foreach ($plays->keyBy('id')->chunk($batchSize) as $batch) {
            $input = $batch->map(fn (WdbPlay|WdbDanPlay $play): string => json_encode([
                'id' => $play->id,
                'baid' => (int) $play->baid,
                'engine_version' => $play->engine_version,
                'score' => (int) $play->score,
                ...$fields($play),
            ], JSON_THROW_ON_ERROR))->implode("\n")."\n";
            $run = Process::input($input)->timeout(600)->path(sys_get_temp_dir())->run([$analyzer]);
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
    private function apply(WdbPlay|WdbDanPlay $play, string $flag, ?array $analysis): void
    {
        $isNewFlag = $flag !== 'none' && $play->analysis_flag !== $flag;
        $play->update(['analysis_flag' => $flag, 'analysis' => $analysis, 'analyzed_at' => now()]);
        if ($isNewFlag) {
            WaddamburoUploadWarnings::record((int) $play->baid, UploadWarningKind::AnalyzerFlag, $play->id,
                ($play instanceof WdbDanPlay ? 'Dan run. ' : '')."Flag: {$flag}");
        }
    }

    /**
     * One chart's play: its gzipped timeline and replay (base64), and the other players' plays to compare it with.
     *
     * @return array<string, mixed>
     */
    private function song(int $chartId, int $baid, ?string $timeline, ?string $replay): array
    {
        return [
            'chart' => $chartId,
            'timeline' => base64_encode((string) $timeline),
            'replay' => base64_encode((string) $replay),
            'others' => $this->others($chartId, $baid),
        ];
    }

    /**
     * Other players' best plays on the chart and their dan runs' songs on it, not ones staff hid.
     *
     * @return list<array{id: string, kind: string, baid: int, timeline: string}>
     */
    private function others(int $chartId, int $baid): array
    {
        $plays = WdbPlay::query()->where('wdb_chart_id', $chartId)->where('baid', '!=', $baid)
            ->whereNull('hidden_at')->whereNotNull('timeline')->orderByDesc('score')->limit(self::COMPARED_PLAYS)
            ->get(['id', 'baid', 'timeline'])
            ->map(fn (WdbPlay $other): array => ['id' => $other->id, 'kind' => 'play', 'baid' => (int) $other->baid, 'timeline' => base64_encode((string) $other->timeline)]);
        $danSongs = WdbDanPlaySong::query()->where('wdb_chart_id', $chartId)->whereNotNull('timeline')
            ->whereHas('play', fn ($play) => $play->where('baid', '!=', $baid)->whereNull('hidden_at'))
            ->with('play:id,baid')->latest('id')->limit(self::COMPARED_PLAYS)
            ->get(['id', 'wdb_dan_play_id', 'timeline'])
            ->map(fn (WdbDanPlaySong $other): array => ['id' => $other->wdb_dan_play_id, 'kind' => 'dan', 'baid' => (int) $other->play->baid, 'timeline' => base64_encode((string) $other->timeline)]);

        return $plays->concat($danSongs)->values()->all();
    }
}
