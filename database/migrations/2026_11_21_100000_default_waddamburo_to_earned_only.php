<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Waddamburo starts in "Only what I earned", as Green: everyone, until they choose "Unlock everything". */
    public function up(): void
    {
        Schema::table('player_cosmetics', function (Blueprint $table) {
            $table->boolean('wdb_earned_only')->default(true)->change();
        });
        // The mode is new (no player chose yet): everyone starts earned-only.
        DB::table('player_cosmetics')->update(['wdb_earned_only' => true]);
    }

    public function down(): void
    {
        Schema::table('player_cosmetics', function (Blueprint $table) {
            $table->boolean('wdb_earned_only')->default(false)->change();
        });
    }
};
