<?php

namespace App\Console\Commands;

use App\Enums\TaikoGameVersion;
use App\Models\Player;
use App\Models\SongBest;
use App\Models\SongPlayResult;
use App\Services\PlayerRankAggregateService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('app:rebuild-player-version-stats')]
#[Description('Recompute the materialised ranking stats for every player and version')]
class RebuildPlayerVersionStatsCommand extends Command
{
    public function handle(PlayerRankAggregateService $rankAggregates): int
    {
        $bestsTable = (new SongBest)->getTable();
        $resultsTable = (new SongPlayResult)->getTable();

        // Every (baid, version) pair that has any score data.
        $pairs = DB::table($bestsTable)
            ->select('baid', 'game_version')
            ->distinct()
            ->union(
                DB::table($resultsTable)->select('baid', 'game_version')->distinct()
            )
            ->get();

        $players = Player::query()->get()->keyBy('baid');
        $rebuilt = 0;

        foreach ($pairs as $pair) {
            $player = $players->get($pair->baid);
            $version = TaikoGameVersion::tryFrom((string) $pair->game_version);

            if ($player === null || ! $version instanceof TaikoGameVersion) {
                continue;
            }

            $rankAggregates->recompute($player, $version);
            $rebuilt++;
        }

        $this->info("Rebuilt stats for {$rebuilt} player-version pairs.");

        return self::SUCCESS;
    }
}
