<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('players', function (Blueprint $table): void {
            $table->bigIncrements('baid');
            $table->string('mydon_name')->default('');
            $table->unsignedInteger('mydon_name_language')->default(0);
            $table->string('title')->default('');
            $table->unsignedInteger('titleplate_id')->default(0);
            $table->unsignedInteger('color_face')->default(0);
            $table->unsignedInteger('color_body')->default(1);
            $table->unsignedInteger('color_limb')->default(3);
            $table->json('favorite_song_numbers')->default('[]');
            $table->json('recent_song_numbers')->default('[]');
            $table->json('unlocked_song_numbers')->default('[]');
            $table->json('unlocked_costumes')->default('{}');
            $table->unsignedInteger('default_tone_setting')->default(0);
            $table->unsignedInteger('default_option_setting')->default(0);
            $table->unsignedInteger('difficulty_played_course')->default(0);
            $table->unsignedInteger('difficulty_played_star')->default(0);
            $table->unsignedInteger('difficulty_played_sort')->default(0);
            $table->unsignedInteger('total_credit_count')->default(0);
            $table->unsignedInteger('total_get_donmedal')->default(0);
            $table->unsignedInteger('total_use_donmedal')->default(0);
            $table->unsignedInteger('total_get_katsumedal')->default(0);
            $table->unsignedInteger('total_use_katsumedal')->default(0);
            $table->timestampTz('last_played_at')->nullable();
            $table->string('access_token')->default('');
            $table->string('person_id')->default('');
            $table->timestampsTz();
        });

        Schema::create('cards', function (Blueprint $table): void {
            $table->string('access_code')->primary();
            $table->foreignId('baid')->constrained('players', 'baid')->cascadeOnDelete();
            $table->string('chip_id')->default('');
            $table->string('device_type')->default('');
            $table->string('country_id')->default('');
            $table->timestampsTz();
        });

        Schema::create('song_play_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('baid')->constrained('players', 'baid')->cascadeOnDelete();
            $table->string('chassis_id')->default('');
            $table->string('shop_id')->default('');
            $table->timestampTz('played_at')->nullable();
            $table->boolean('is_right')->default(false);
            $table->boolean('is_two_players')->default(false);
            $table->unsignedInteger('song_no');
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
            $table->timestampsTz();
        });

        Schema::create('song_bests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('baid')->constrained('players', 'baid')->cascadeOnDelete();
            $table->unsignedInteger('song_no');
            $table->unsignedInteger('level');
            $table->unsignedInteger('best_score')->default(0);
            $table->unsignedInteger('best_score_rank')->default(0);
            $table->unsignedInteger('best_play_result')->default(0);
            $table->timestampsTz();

            $table->unique(['baid', 'song_no', 'level']);
        });

        Schema::create('tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('baid')->constrained('players', 'baid')->cascadeOnDelete();
            $table->unsignedInteger('token_id');
            $table->integer('count')->default(0);
            $table->timestampsTz();

            $table->unique(['baid', 'token_id']);
        });

        Schema::create('cabinet_bookkeeping_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('chassis_id')->default('');
            $table->string('shop_id')->default('');
            $table->string('update_date')->default('');
            $table->unsignedInteger('all_play_count')->default(0);
            $table->unsignedInteger('service_switch_count')->default(0);
            $table->unsignedInteger('free_play_count')->default(0);
            $table->json('payload')->nullable();
            $table->timestampsTz();
        });

        Schema::create('head_clerk_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('chassis_id')->default('');
            $table->string('shop_id')->default('');
            $table->foreignId('baid')->nullable()->constrained('players', 'baid')->nullOnDelete();
            $table->string('net_id')->default('');
            $table->timestampTz('played_at')->nullable();
            $table->boolean('is_right')->default(false);
            $table->string('place_id')->default('');
            $table->unsignedInteger('type')->default(0);
            $table->unsignedInteger('amount')->default(0);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('head_clerk_logs');
        Schema::dropIfExists('cabinet_bookkeeping_logs');
        Schema::dropIfExists('tokens');
        Schema::dropIfExists('song_bests');
        Schema::dropIfExists('song_play_results');
        Schema::dropIfExists('cards');
        Schema::dropIfExists('players');
    }
};
