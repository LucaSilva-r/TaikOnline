<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Waddamburo now keeps its own Don-chan loadout (it used Green's). Start each player's
 * Waddamburo row as a copy of their Green one so nobody's look resets.
 */
return new class extends Migration
{
    public function up(): void
    {
        $columns = array_values(array_diff(Schema::getColumnListing('player_cosmetics'), ['id', 'game_version']));
        $list = implode(', ', $columns);

        DB::statement(
            "INSERT INTO player_cosmetics (game_version, {$list})
             SELECT 'waddamburo', {$list} FROM player_cosmetics g
             WHERE g.game_version = 'green'
               AND NOT EXISTS (SELECT 1 FROM player_cosmetics w WHERE w.baid = g.baid AND w.game_version = 'waddamburo')"
        );
    }

    public function down(): void
    {
        DB::table('player_cosmetics')->where('game_version', 'waddamburo')->delete();
    }
};
