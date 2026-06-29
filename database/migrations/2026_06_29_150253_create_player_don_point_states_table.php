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
        Schema::create('player_don_point_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('baid')->constrained('players', 'baid')->cascadeOnDelete();
            $table->string('game_version');
            $table->unsignedInteger('total_get_donpoint')->default(0);
            $table->unsignedInteger('total_use_donpoint')->default(0);
            $table->unsignedInteger('reward_ptn')->default(0);
            $table->unsignedInteger('reward_progress')->default(0);
            $table->timestampsTz();

            $table->unique(['baid', 'game_version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('player_don_point_states');
    }
};
