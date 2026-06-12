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
        Schema::create('player_green_ghost_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('baid')->constrained('players', 'baid')->cascadeOnDelete();
            $table->unsignedInteger('token_id');
            $table->unsignedInteger('token_value')->default(0);
            $table->timestampsTz();

            $table->unique(['baid', 'token_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('player_green_ghost_tokens');
    }
};
