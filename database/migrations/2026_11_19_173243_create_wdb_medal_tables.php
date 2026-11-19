<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Waddamburo's Don Medals. Every finished song is a row (the client's id, so re-sends are idempotent);
 * songs make credits (an arcade credit, a dan course, or three songs at home) and each credit pays
 * Green's medals once. The wallet is the credits' medals minus the Waddamburo shop purchases.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wdb_medal_songs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('baid')->constrained('players', 'baid')->cascadeOnDelete();
            $table->uuid('credit_id')->nullable();
            $table->string('kind', 8); // song | dan
            $table->date('local_day'); // the player's own calendar day of the play
            $table->timestampTz('played_at');
            $table->timestampsTz();

            $table->index(['baid', 'credit_id']);
        });

        Schema::create('wdb_medal_credits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('baid')->constrained('players', 'baid')->cascadeOnDelete();
            $table->date('local_day');
            $table->unsignedSmallInteger('season_id');
            $table->unsignedInteger('medals');
            $table->boolean('daily');
            $table->boolean('weekly');
            $table->boolean('monthly');
            $table->boolean('announced')->default(false);
            $table->timestampsTz();

            $table->index(['baid', 'local_day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wdb_medal_credits');
        Schema::dropIfExists('wdb_medal_songs');
    }
};
