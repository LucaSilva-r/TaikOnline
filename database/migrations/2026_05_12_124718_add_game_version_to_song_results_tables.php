<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('song_play_results', function (Blueprint $table): void {
            $table->string('game_version')->default('green')->after('baid');
            $table->index(['game_version', 'song_no', 'level']);
        });

        Schema::table('song_bests', function (Blueprint $table): void {
            $table->dropUnique(['baid', 'song_no', 'level']);
            $table->string('game_version')->default('green')->after('baid');
            $table->unique(['baid', 'game_version', 'song_no', 'level']);
            $table->index(['game_version', 'song_no', 'level']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('song_bests', function (Blueprint $table): void {
            $table->dropIndex(['game_version', 'song_no', 'level']);
            $table->dropUnique(['baid', 'game_version', 'song_no', 'level']);
            $table->dropColumn('game_version');
            $table->unique(['baid', 'song_no', 'level']);
        });

        Schema::table('song_play_results', function (Blueprint $table): void {
            $table->dropIndex(['game_version', 'song_no', 'level']);
            $table->dropColumn('game_version');
        });
    }
};
