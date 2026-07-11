<?php

namespace App\Console\Commands;

use App\Enums\TaikoGameVersion;
use App\Models\PlayerRankSnapshot;
use App\Models\PlayerVersionStats;
use App\Services\ExtraRankAggregateService;
use App\Services\PlayerRankAggregateService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:record-player-rank-snapshots {version? : Game version (omit to snapshot every version)}')]
#[Description('Record daily public board rank snapshots for player profiles')]
class RecordPlayerRankSnapshotsCommand extends Command
{
    public function handle(
        PlayerRankAggregateService $rankAggregates,
        ExtraRankAggregateService $extraRankAggregates,
    ): int {
        $versionArg = $this->argument('version');

        if ($versionArg !== null) {
            $version = (string) $versionArg;
            $scope = $version === ExtraRankAggregateService::SCOPE
                ? ExtraRankAggregateService::SCOPE
                : TaikoGameVersion::fromInput($version);

            if (! $scope instanceof TaikoGameVersion && $scope !== ExtraRankAggregateService::SCOPE) {
                $this->error("Unknown game version: {$versionArg}");

                return self::FAILURE;
            }

            $scopes = [$scope];
        } else {
            $scopes = [...TaikoGameVersion::cases(), ExtraRankAggregateService::SCOPE];
        }

        $snapshotDate = today();
        $recorded = 0;

        foreach ($scopes as $scope) {
            $scopeName = $scope instanceof TaikoGameVersion ? $scope->value : $scope;
            $aggregates = $scope instanceof TaikoGameVersion
                ? $rankAggregates->forVersion($scope)
                : $extraRankAggregates->standings()->values()->map(function (PlayerVersionStats $stats, int $index): array {
                    return [
                        'user_id' => (int) $stats->user_id,
                        'rank' => $index + 1,
                        'total_score' => (int) $stats->total_score,
                        'ranked_song_count' => (int) $stats->ranked_song_count,
                        'played_song_count' => (int) $stats->played_song_count,
                        'crown_counts' => [
                            'none' => (int) $stats->crown_none,
                            'clear' => (int) $stats->crown_clear,
                            'gold' => (int) $stats->crown_gold,
                            'dondaful' => (int) $stats->crown_dondaful,
                        ],
                    ];
                });

            foreach ($aggregates as $aggregate) {
                PlayerRankSnapshot::query()->updateOrCreate(
                    [
                        'user_id' => $aggregate['user_id'],
                        'game_version' => $scopeName,
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

            $this->info(sprintf('%s: %d snapshots recorded', $scopeName, $aggregates->count()));
        }

        $this->info("Total snapshots recorded: {$recorded}");

        return self::SUCCESS;
    }
}
