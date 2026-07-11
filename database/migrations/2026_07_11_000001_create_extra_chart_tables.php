<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extra_songs', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('edition')->nullable();
            $table->boolean('is_ranked')->default(true);
            $table->timestamps();
        });

        Schema::create('extra_charts', function (Blueprint $table): void {
            $table->id();
            $table->char('sha256', 64)->unique();
            $table->foreignId('extra_song_id')->nullable()->constrained('extra_songs')->nullOnDelete();
            $table->unsignedTinyInteger('difficulty')->nullable();
            $table->string('observed_title')->nullable();
            $table->string('observed_source_id')->nullable();
            $table->timestampTz('first_seen_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['extra_song_id', 'difficulty']);
            $table->index(['extra_song_id', 'sha256']);
        });

        Schema::create('extra_chart_play_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('baid')->constrained('players', 'baid')->cascadeOnDelete();
            $table->foreignId('extra_chart_id')->constrained('extra_charts')->cascadeOnDelete();
            $table->string('origin_game_version');
            $table->string('chassis_id')->default('');
            $table->string('shop_id')->default('');
            $table->char('session_hash', 64);
            $table->timestampTz('played_at')->nullable();
            $table->unsignedTinyInteger('stage_index')->default(0);
            $table->boolean('is_right')->default(false);
            $table->boolean('is_two_players')->default(false);
            $table->unsignedInteger('runtime_song_no');
            $table->unsignedInteger('level');
            $table->unsignedInteger('stage_mode')->default(0);
            $table->unsignedInteger('play_result')->default(0);
            $table->unsignedInteger('score')->default(0);
            $table->unsignedInteger('score_rank')->default(0);
            $table->unsignedInteger('good_count')->default(0);
            $table->unsignedInteger('ok_count')->default(0);
            $table->unsignedInteger('miss_count')->default(0);
            $table->unsignedInteger('drumroll_count')->default(0);
            $table->unsignedInteger('combo_count')->default(0);
            $table->unsignedInteger('hit_count')->default(0);
            $table->unsignedInteger('music_category')->default(0);
            $table->unsignedInteger('selected_folder_id')->default(0);
            $table->json('raw_stage')->nullable();
            $table->timestamps();

            $table->unique(['session_hash', 'stage_index']);
            $table->index(['baid', 'played_at']);
            $table->index(['extra_chart_id', 'score']);
        });

        Schema::create('extra_chart_bests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('baid')->constrained('players', 'baid')->cascadeOnDelete();
            $table->foreignId('extra_chart_id')->constrained('extra_charts')->cascadeOnDelete();
            $table->boolean('is_shin')->default(false);
            $table->unsignedInteger('best_score')->default(0);
            $table->unsignedInteger('best_score_rank')->default(0);
            $table->unsignedInteger('best_play_result')->default(0);
            $table->unsignedInteger('best_crown')->default(0);
            $table->timestamps();

            $table->unique(['baid', 'extra_chart_id', 'is_shin']);
            $table->index(['extra_chart_id', 'best_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extra_chart_bests');
        Schema::dropIfExists('extra_chart_play_results');
        Schema::dropIfExists('extra_charts');
        Schema::dropIfExists('extra_songs');
    }
};
