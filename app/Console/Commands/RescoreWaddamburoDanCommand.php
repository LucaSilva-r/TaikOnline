<?php

namespace App\Console\Commands;

use App\Models\WdbDanPlay;
use App\Services\WaddamburoDanScorer;
use Illuminate\Console\Command;

/** Scores dan plays again: after a rules change (a new binary), or those a scorer never got to. */
class RescoreWaddamburoDanCommand extends Command
{
    protected $signature = 'app:rescore-waddamburo-dan {--all : Every play, not only those not scored yet}';

    protected $description = 'Score Waddamburo dan plays from their replays';

    public function handle(WaddamburoDanScorer $scorer): int
    {
        $query = WdbDanPlay::query()->when(! $this->option('all'), fn ($plays) => $plays->whereNull('rescored_at'));
        $count = 0;
        $query->chunkById(20, function ($plays) use ($scorer, &$count): void {
            $scorer->rescore($plays);
            $count += $plays->count();
        }, 'id');
        $this->info("Scored {$count} dan play(s).");

        return self::SUCCESS;
    }
}
