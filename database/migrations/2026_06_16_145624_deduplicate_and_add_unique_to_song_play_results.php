<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remove duplicate rows, keeping the lowest id for each natural key.
        // Duplicates arise when a cabinet retries a save after an ambiguous response.
        DB::statement('
            DELETE FROM song_play_results
            WHERE id NOT IN (
                SELECT MIN(id)
                FROM song_play_results
                GROUP BY baid, game_version, song_no, level, played_at
            )
        ');

        Schema::table('song_play_results', function (Blueprint $table): void {
            $table->unique(['baid', 'game_version', 'song_no', 'level', 'played_at'], 'song_play_results_natural_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('song_play_results', function (Blueprint $table): void {
            $table->dropUnique('song_play_results_natural_key_unique');
        });
    }
};
