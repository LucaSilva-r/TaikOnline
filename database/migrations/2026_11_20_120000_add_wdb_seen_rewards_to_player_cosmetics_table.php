<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Waddamburo: the earned rewards the game already showed ("type:id"), so its rewards page shows only new ones. */
    public function up(): void
    {
        Schema::table('player_cosmetics', function (Blueprint $table) {
            $table->json('wdb_seen_rewards')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('player_cosmetics', function (Blueprint $table) {
            $table->dropColumn('wdb_seen_rewards');
        });
    }
};
