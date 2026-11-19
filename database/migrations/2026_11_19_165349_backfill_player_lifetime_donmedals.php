<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * players.total_get/use_donmedal is now the lifetime total across versions. Before, play
 * results added medals either to the season row (shop on) or to the player row (shop off),
 * and purchases only to the season row, so the lifetime total is the player row plus the
 * season rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->shift('+');
    }

    public function down(): void
    {
        $this->shift('-');
    }

    private function shift(string $sign): void
    {
        $sum = fn (string $column): string => "(SELECT COALESCE(SUM(s.{$column}), 0) FROM player_shop_season_states s WHERE s.baid = players.baid)";

        DB::statement(
            "UPDATE players SET
                total_get_donmedal = total_get_donmedal {$sign} {$sum('total_get_donmedal')},
                total_use_donmedal = total_use_donmedal {$sign} {$sum('total_use_donmedal')}
             WHERE EXISTS (SELECT 1 FROM player_shop_season_states s WHERE s.baid = players.baid)"
        );
    }
};
