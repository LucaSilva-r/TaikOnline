<?php

namespace App\Console\Commands;

use App\Models\Player;
use App\Models\WdbDanPlay;
use App\Models\WdbPlay;
use App\Services\WaddamburoRankAggregateService;
use Illuminate\Console\Command;

/**
 * Deletes failed plays and dan runs, including those the server's own scoring failed after upload. Hidden or
 * reported ones stay: they are evidence.
 */
class CullFailedWaddamburoPlaysCommand extends Command
{
    protected $signature = 'app:cull-failed-waddamburo-plays {--dry-run : Only count them}';

    protected $description = 'Delete failed Waddamburo plays and dan runs (run VACUUM FULL afterwards to shrink the tables)';

    public function handle(WaddamburoRankAggregateService $aggregates): int
    {
        $plays = WdbPlay::query()->where('cleared', false)->whereNull('hidden_at')
            ->whereDoesntHave('reports')
            ->whereNotIn('id', fn ($actions) => $actions->select('wdb_play_id')->from('moderation_actions')->whereNotNull('wdb_play_id'));
        $runs = WdbDanPlay::query()->where('result_level', 0)->whereNull('hidden_at')
            ->whereDoesntHave('reports')
            ->whereNotIn('id', fn ($actions) => $actions->select('wdb_dan_play_id')->from('moderation_actions')->whereNotNull('wdb_dan_play_id'));

        if ($this->option('dry-run')) {
            $this->info("Would delete {$plays->count()} failed play(s) and {$runs->count()} failed dan run(s).");

            return self::SUCCESS;
        }

        $baids = (clone $plays)->distinct()->pluck('baid');
        $deleted = $plays->delete();
        $deletedRuns = $runs->delete();
        // Their scores counted towards the standings until now.
        Player::query()->whereIn('baid', $baids)->get()->each(fn (Player $player) => $aggregates->recompute($player));
        $this->info("Deleted {$deleted} failed play(s) and {$deletedRuns} failed dan run(s).");

        return self::SUCCESS;
    }
}
