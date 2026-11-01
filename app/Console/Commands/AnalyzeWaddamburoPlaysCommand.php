<?php

namespace App\Console\Commands;

use App\Models\WdbDanPlay;
use App\Models\WdbPlay;
use App\Services\WaddamburoAnalyzer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/** Runs the analyzer over stored plays and dan runs: the ones not analyzed yet, or with --all every one (after the analyzer changes). */
#[Signature('app:analyze-waddamburo-plays {--all : Analyze every play, not only the ones never analyzed}')]
#[Description('Run the private analyzer over stored Waddamburo plays')]
class AnalyzeWaddamburoPlaysCommand extends Command
{
    public function handle(WaddamburoAnalyzer $analyzer): int
    {
        if (! WaddamburoAnalyzer::isAvailable()) {
            $this->error('WADDAMBURO_ANALYZER is not set to an executable.');

            return self::FAILURE;
        }
        $totals = ['analyzed' => 0, 'failed' => 0, 'flagged' => 0];
        WdbPlay::query()->whereNotNull('timeline')->when(! $this->option('all'), fn ($query) => $query->whereNull('analyzed_at'))
            ->chunkById(100, function (Collection $plays) use ($analyzer, &$totals): void {
                foreach ($analyzer->analyze($plays) as $key => $count) {
                    $totals[$key] += $count;
                }
            });
        $this->info("Analyzed {$totals['analyzed']} plays: {$totals['flagged']} flagged, {$totals['failed']} failed.");

        $totals = ['analyzed' => 0, 'failed' => 0, 'flagged' => 0];
        WdbDanPlay::query()->when(! $this->option('all'), fn ($query) => $query->whereNull('analyzed_at'))
            ->chunkById(20, function (Collection $plays) use ($analyzer, &$totals): void {
                foreach ($analyzer->analyzeDan($plays) as $key => $count) {
                    $totals[$key] += $count;
                }
            }, 'id');
        $this->info("Analyzed {$totals['analyzed']} dan runs: {$totals['flagged']} flagged, {$totals['failed']} failed.");

        return self::SUCCESS;
    }
}
