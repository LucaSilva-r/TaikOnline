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

#[Signature('app:recalculate-crowns')]
#[Description('Recalculate best_crown for all song_bests from actual note counts, then rebuild player stats')]
class RecalculateCrownsCommand extends Command
{
    public function handle(PlayerRankAggregateService $rankAggregates): int
    {
        $bestsTable = (new SongBest)->getTable();
        $resultsTable = (new SongPlayResult)->getTable();

        // Recompute best_crown for every song_best from the actual good/ok/miss
        // counts stored in song_play_results. The cabinet's reported play_result
        // field is ignored because some versions do not set it correctly.
        $updated = DB::update("
            UPDATE {$bestsTable} sb
            SET best_crown = computed.crown
            FROM (
                SELECT
                    baid,
                    game_version,
                    song_no,
                    level,
                    (stage_mode = ANY(ARRAY[1, 4])) AS is_shin,
                    MAX(
                        CASE
                            WHEN miss_count = 0 AND ok_count = 0 THEN 3
                            WHEN miss_count = 0 THEN 2
                            ELSE 1
                        END
                    ) AS crown
                FROM {$resultsTable}
                GROUP BY baid, game_version, song_no, level, (stage_mode = ANY(ARRAY[1, 4]))
            ) computed
            WHERE sb.baid = computed.baid
                AND sb.game_version = computed.game_version
                AND sb.song_no = computed.song_no
                AND sb.level = computed.level
                AND sb.is_shin = computed.is_shin
        ");

        $this->info("Updated best_crown on {$updated} song_best rows.");

        // Rebuild the materialised ranking stats so crown counts reflect the fix.
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
