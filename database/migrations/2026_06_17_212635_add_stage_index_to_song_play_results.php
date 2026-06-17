<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('song_play_results', function (Blueprint $table): void {
            $table->unsignedTinyInteger('stage_index')->default(0)->after('played_at');
        });

        // A cabinet sends one played_at for the whole session (up to 4 songs),
        // so the same song+level can legitimately repeat within a session. The
        // old natural key forbade that; rank stages by their position instead.
        DB::statement('
            UPDATE song_play_results
            SET stage_index = (
                SELECT COUNT(*)
                FROM song_play_results AS earlier
                WHERE earlier.baid = song_play_results.baid
                    AND earlier.game_version = song_play_results.game_version
                    AND earlier.played_at = song_play_results.played_at
                    AND earlier.id < song_play_results.id
            )
        ');

        Schema::table('song_play_results', function (Blueprint $table): void {
            $table->dropUnique('song_play_results_natural_key_unique');
            $table->unique(
                ['baid', 'game_version', 'played_at', 'stage_index'],
                'song_play_results_session_stage_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('song_play_results', function (Blueprint $table): void {
            $table->dropUnique('song_play_results_session_stage_unique');
            $table->unique(
                ['baid', 'game_version', 'song_no', 'level', 'played_at'],
                'song_play_results_natural_key_unique'
            );
            $table->dropColumn('stage_index');
        });
    }
};
