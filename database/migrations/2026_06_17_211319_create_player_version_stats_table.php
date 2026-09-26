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
        Schema::create('player_version_stats', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('baid');
            $table->string('game_version');
            $table->unsignedBigInteger('user_id')->nullable();

            $table->unsignedBigInteger('total_score')->default(0);
            $table->unsignedInteger('ranked_song_count')->default(0);
            $table->unsignedInteger('played_song_count')->default(0);

            $table->unsignedInteger('crown_none')->default(0);
            $table->unsignedInteger('crown_clear')->default(0);
            $table->unsignedInteger('crown_gold')->default(0);
            $table->unsignedInteger('crown_dondaful')->default(0);

            $table->unsignedBigInteger('good_total')->default(0);
            $table->unsignedBigInteger('ok_total')->default(0);
            $table->unsignedBigInteger('miss_total')->default(0);
            $table->decimal('precision', 5, 2)->default(0);

            $table->timestamps();

            $table->unique(['baid', 'game_version']);
            // Ranking reads: filter by version + linked user, ordered by score.
            $table->index(['game_version', 'user_id', 'total_score']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('player_version_stats');
    }
};
