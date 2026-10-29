<?php

namespace App\Console\Commands;

use App\Models\WdbPlay;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Works the plays' roll speeds (WdbPlay::rollSpeed) out again from their stored judgement timelines,
 * after the analysis changes: no replay is rescored and no Waddamburo binary is needed. Plays without a
 * timeline keep none (their next rescore brings one).
 */
#[Signature('app:recompute-roll-speed')]
#[Description('Recompute every Waddamburo play\'s roll speed from its stored judgement timeline')]
class RecomputeRollSpeedCommand extends Command
{
    public function handle(): int
    {
        $query = WdbPlay::query()->whereNotNull('timeline');
        $bar = $this->output->createProgressBar((clone $query)->count());
        $query->select(['id', 'timeline'])->chunkById(200, function (Collection $plays) use ($bar): void {
            foreach ($plays as $play) {
                if (($timeline = $play->judgementTimeline()) !== null) {
                    $play->newQuery()->whereKey($play->id)->update(WdbPlay::rollSpeed($timeline));
                }
                $bar->advance();
            }
        });
        $bar->finish();
        $this->newLine(2);
        $this->info('Roll speeds recomputed.');

        return self::SUCCESS;
    }
}
