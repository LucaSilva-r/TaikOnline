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
        $indexes = collect(Schema::getIndexes('song_play_results'))->pluck('name');
        $hasColumn = Schema::hasColumn('song_play_results', 'session_hash');

        Schema::table('song_play_results', function (Blueprint $table) use ($indexes, $hasColumn) {
            // Replace the played_at-based session dedup constraint with a content-addressed hash.
            // The hash is computed from baid + game_version + chassis_id + raw cabinet datetime,
            // so cabinet clock bugs (e.g. year 644003) no longer cause timestamp overflow errors.
            // All stages in a session share the same hash; a plain index is enough for the EXISTS check.
            if ($indexes->contains('song_play_results_session_stage_unique')) {
                $table->dropUnique('song_play_results_session_stage_unique');
            }

            if (! $hasColumn) {
                $table->string('session_hash', 64)->nullable()->index()->after('shop_id');
            } elseif ($indexes->contains('song_play_results_session_hash_unique')) {
                // Column was added with a unique constraint on a botched deploy — replace with a plain index.
                $table->dropUnique(['session_hash']);
                $table->index('session_hash');
            }
        });
    }

    public function down(): void
    {
        Schema::table('song_play_results', function (Blueprint $table) {
            $table->dropIndex(['session_hash']);
            $table->dropColumn('session_hash');
            $table->unique(
                ['baid', 'game_version', 'played_at', 'stage_index'],
                'song_play_results_session_stage_unique'
            );
        });
    }
};
