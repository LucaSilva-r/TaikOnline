<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_blue_battle_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('baid')->constrained('players', 'baid')->cascadeOnDelete();
            $table->binary('release_info_flg')->nullable();
            $table->binary('release_battle_stage_flg')->nullable();
            $table->unsignedInteger('last_battle_stage_id')->default(0);
            $table->unsignedInteger('last_boss_life')->default(0);
            $table->unsignedInteger('last_npc_id')->default(0);
            $table->unsignedInteger('assign_stage_id')->default(1);
            $table->timestampsTz();

            $table->unique('baid');
        });

        Schema::create('player_blue_battle_npc_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('baid')->constrained('players', 'baid')->cascadeOnDelete();
            $table->unsignedInteger('npc_id');
            $table->unsignedBigInteger('total_exp')->default(0);
            $table->unsignedInteger('max_dpn')->default(0);
            $table->unsignedInteger('npc_costume_id')->default(0);
            $table->binary('npc_costume_flg')->nullable();
            $table->unsignedInteger('selected_special_id_1')->default(1);
            $table->unsignedInteger('selected_special_id_2')->default(0);
            $table->unsignedInteger('selected_special_id_3')->default(0);
            $table->binary('release_special_flg')->nullable();
            $table->unsignedInteger('bonds_level')->default(1);
            $table->timestampsTz();

            $table->unique(['baid', 'npc_id']);
        });

        Schema::create('player_blue_battle_token_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('baid')->constrained('players', 'baid')->cascadeOnDelete();
            $table->unsignedInteger('token_id');
            $table->unsignedInteger('token_value')->default(0);
            $table->timestampsTz();

            $table->unique(['baid', 'token_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_blue_battle_token_states');
        Schema::dropIfExists('player_blue_battle_npc_states');
        Schema::dropIfExists('player_blue_battle_states');
    }
};
