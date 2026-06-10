<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('song_bests', function (Blueprint $table): void {
            // 0=none, 1=clear, 2=gold (full combo), 3=dondaful — mirrors the
            // cabinet's play_result crown ranks, kept as the player's best per
            // (baid, game_version, song_no, level).
            $table->unsignedTinyInteger('best_crown')->default(0)->after('best_play_result');
        });
    }

    public function down(): void
    {
        Schema::table('song_bests', function (Blueprint $table): void {
            $table->dropColumn('best_crown');
        });
    }
};
