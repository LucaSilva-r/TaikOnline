<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Waddamburo's title parts gacha: the parts a player drew ("banner:index") and the Katsu Medals it cost. */
    public function up(): void
    {
        Schema::table('player_cosmetics', function (Blueprint $table) {
            $table->json('wdb_title_parts')->nullable();
            $table->unsignedInteger('wdb_gacha_spent')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('player_cosmetics', function (Blueprint $table) {
            $table->dropColumn(['wdb_title_parts', 'wdb_gacha_spent']);
        });
    }
};
