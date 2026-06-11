<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_rank_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('game_version');
            $table->unsignedInteger('rank');
            $table->unsignedBigInteger('total_score')->default(0);
            $table->unsignedInteger('ranked_song_count')->default(0);
            $table->unsignedInteger('played_song_count')->default(0);
            $table->json('crown_counts')->default('{}');
            $table->date('snapshot_date');
            $table->timestampsTz();

            $table->unique(['user_id', 'game_version', 'snapshot_date']);
            $table->index(['game_version', 'snapshot_date']);
            $table->index(['game_version', 'rank']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_rank_snapshots');
    }
};
