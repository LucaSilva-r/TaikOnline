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
        Schema::create('player_green_ghost_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('baid')->constrained('players', 'baid')->cascadeOnDelete();
            $table->binary('release_info_flag')->nullable();
            $table->unsignedBigInteger('total_winnings')->default(0);
            $table->integer('input_median')->default(0);
            $table->unsignedInteger('input_variance')->default(0);
            $table->unsignedInteger('rank_id')->default(1);
            $table->unsignedInteger('win_point')->default(0);
            $table->unsignedInteger('certified_level_id')->default(0);
            $table->timestampsTz();

            $table->unique('baid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('player_green_ghost_states');
    }
};
