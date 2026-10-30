<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A dan course's play and each of its songs: one replay per song, on that song's own time. A course is
     * named by its content hash, as the same course can be in several series. rescored_at stays null until
     * the scorer has replayed it, and only rescored plays count.
     */
    public function up(): void
    {
        Schema::create('wdb_dan_plays', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('baid')->constrained('players', 'baid')->cascadeOnDelete();
            $table->char('content_hash', 64)->index();
            $table->unsignedTinyInteger('result_level');
            $table->unsignedInteger('score');
            $table->unsignedInteger('great');
            $table->unsignedInteger('good');
            $table->unsignedInteger('miss');
            $table->unsignedInteger('max_combo');
            $table->unsignedInteger('rolls');
            $table->unsignedTinyInteger('gauge_percent');
            $table->unsignedTinyInteger('songs_played');
            $table->unsignedInteger('scoring_version');
            $table->string('engine_version', 64);
            $table->integer('audio_offset_ms')->nullable();
            $table->integer('input_offset_ms')->nullable();
            $table->timestampTz('played_at');
            $table->timestampTz('rescored_at')->nullable();
            $table->timestampTz('hidden_at')->nullable();
            $table->string('hidden_reason')->nullable();
            $table->timestamps();
            $table->index(['baid', 'content_hash']);
        });
        Schema::create('wdb_dan_play_songs', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('wdb_dan_play_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->foreignId('wdb_chart_id')->constrained('wdb_charts');
            $table->unsignedInteger('great')->default(0);
            $table->unsignedInteger('good')->default(0);
            $table->unsignedInteger('miss')->default(0);
            $table->unsignedInteger('rolls')->default(0);
            $table->binary('replay');
            $table->timestamps();
            $table->unique(['wdb_dan_play_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wdb_dan_play_songs');
        Schema::dropIfExists('wdb_dan_plays');
    }
};
