<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_tokkun_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('baid')->constrained('players', 'baid')->cascadeOnDelete();
            $table->string('game_version');
            $table->unsignedInteger('tokkun_tutorial_flg')->default(0);
            $table->timestampsTz();

            $table->unique(['baid', 'game_version']);
        });

        Schema::create('player_tokkun_stage_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('baid')->constrained('players', 'baid')->cascadeOnDelete();
            $table->string('game_version');
            $table->timestampTz('played_at')->nullable();
            $table->unsignedInteger('play_mode');
            $table->string('banacoin_datetime')->nullable();
            $table->unsignedInteger('tokkun_song_count')->default(0);
            $table->json('tokkun_song_numbers')->default('[]');
            $table->unsignedInteger('tokkun_speedchange_count')->default(0);
            $table->unsignedInteger('tokkun_autoplay_count')->default(0);
            $table->unsignedInteger('tokkun_jump_count')->default(0);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_tokkun_stage_results');
        Schema::dropIfExists('player_tokkun_states');
    }
};
