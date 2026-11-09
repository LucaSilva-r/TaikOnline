<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Waddamburo's AI battles, one per play (the play's id): won, each section's outcome ('W'/'L'), the support level
     * (0 = いつもの), the rank points it earned and the player's hit timing (median ms, variance ms²). A player's rank and
     * wins derive from these. A failed battle is kept here (it can still be a win) but never becomes a play.
     */
    public function up(): void
    {
        Schema::create('wdb_ai_battles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('baid')->index();
            $table->foreignId('wdb_chart_id')->constrained('wdb_charts')->cascadeOnDelete();
            $table->boolean('won');
            $table->string('sections', 8);
            $table->unsignedTinyInteger('support_level');
            $table->unsignedTinyInteger('points');
            $table->float('hit_median')->nullable();
            $table->float('hit_variance')->nullable();
            $table->timestamp('played_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wdb_ai_battles');
    }
};
