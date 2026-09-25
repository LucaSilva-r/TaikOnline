<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Waddamburo's own scoreboard: every play (with its replay) against charts identified by the
 * SHA-256 of their canonical parsed form. Charts are imported (notes only) so plays can be rescored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wdb_charts', function (Blueprint $table) {
            $table->id();
            $table->char('sha256', 64)->unique();
            $table->binary('notes')->nullable();
            $table->string('title')->nullable();
            $table->string('subtitle')->nullable();
            $table->string('source')->nullable();
            $table->unsignedTinyInteger('course')->nullable();
            $table->unsignedTinyInteger('level')->nullable();
            $table->timestampTz('ranked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('wdb_plays', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('baid')->constrained('players', 'baid')->cascadeOnDelete();
            $table->foreignId('wdb_chart_id')->constrained('wdb_charts')->cascadeOnDelete();
            $table->string('mode', 32);
            $table->unsignedTinyInteger('course');
            $table->unsignedInteger('score');
            $table->unsignedInteger('great');
            $table->unsignedInteger('good');
            $table->unsignedInteger('miss');
            $table->unsignedInteger('max_combo');
            $table->unsignedInteger('rolls');
            $table->unsignedTinyInteger('gauge');
            $table->boolean('cleared');
            $table->unsignedInteger('scoring_version');
            $table->string('engine_version', 64);
            $table->timestampTz('played_at');
            $table->binary('replay');
            $table->timestamps();

            $table->index(['baid', 'wdb_chart_id']);
            $table->index(['wdb_chart_id', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wdb_plays');
        Schema::dropIfExists('wdb_charts');
    }
};
