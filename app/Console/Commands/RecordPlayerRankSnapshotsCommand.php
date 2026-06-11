<?php

namespace App\Console\Commands;

use App\Enums\TaikoGameVersion;
use App\Models\PlayerRankSnapshot;
use App\Services\PlayerRankAggregateService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:record-player-rank-snapshots {version? : Game version (omit to snapshot every version)}')]
#[Description('Record daily public board rank snapshots for player profiles')]
class RecordPlayerRankSnapshotsCommand extends Command
{
    public function handle(PlayerRankAggregateService $rankAggregates): int
    {
        $versionArg = $this->argument('version');

        if ($versionArg !== null) {
            $version = TaikoGameVersion::fromInput((string) $versionArg);
            if (! $version instanceof TaikoGameVersion) {
                $this->error("Unknown game version: {$versionArg}");

                return self::FAILURE;
            }

            $versions = [$version];
        } else {
            $versions = TaikoGameVersion::cases();
        }

        $snapshotDate = today();
        $recorded = 0;

        foreach ($versions as $version) {
            $aggregates = $rankAggregates->forVersion($version);

            foreach ($aggregates as $aggregate) {
                PlayerRankSnapshot::query()->updateOrCreate(
                    [
                        'user_id' => $aggregate['user_id'],
                        'game_version' => $version->value,
                        'snapshot_date' => $snapshotDate,
                    ],
                    [
                        'rank' => $aggregate['rank'],
                        'total_score' => $aggregate['total_score'],
                        'ranked_song_count' => $aggregate['ranked_song_count'],
                        'played_song_count' => $aggregate['played_song_count'],
                        'crown_counts' => $aggregate['crown_counts'],
                    ],
                );

                $recorded++;
            }

            $this->info(sprintf('%s: %d snapshots recorded', $version->value, $aggregates->count()));
        }

        $this->info("Total snapshots recorded: {$recorded}");

        return self::SUCCESS;
    }
}
