<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Waddamburo's unlock mode: false "Unlock everything" (default), true "Only what I earned". */
    public function up(): void
    {
        Schema::table('player_cosmetics', function (Blueprint $table) {
            $table->boolean('wdb_earned_only')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('player_cosmetics', function (Blueprint $table) {
            $table->dropColumn('wdb_earned_only');
        });
    }
};
