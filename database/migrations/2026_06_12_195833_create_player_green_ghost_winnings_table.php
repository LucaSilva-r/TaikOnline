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
        Schema::create('player_green_ghost_winnings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('baid')->constrained('players', 'baid')->cascadeOnDelete();
            $table->unsignedInteger('level_id');
            $table->unsignedInteger('winnings')->default(0);
            $table->timestampsTz();

            $table->unique(['baid', 'level_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('player_green_ghost_winnings');
    }
};
