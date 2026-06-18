<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_dan_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('baid')->constrained('players', 'baid')->cascadeOnDelete();
            $table->string('game_version');
            // Packed 2-bit-per-dan clear-grade bitfield echoed as got_dan_flg.
            $table->binary('got_dan_flg')->nullable();
            $table->unsignedInteger('got_dan_max')->default(0);
            // Dan shown in the Taikojuku menu; 1 is the safe "no progress" slot.
            $table->unsignedInteger('disp_taikojuku_dan')->default(1);
            $table->timestampsTz();

            $table->unique(['baid', 'game_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_dan_progress');
    }
};
