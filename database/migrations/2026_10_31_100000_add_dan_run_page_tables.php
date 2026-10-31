<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** What a dan run's page shows: each song's timeline, the course's progress, and reports on the run. */
    public function up(): void
    {
        Schema::table('wdb_dan_play_songs', function (Blueprint $table) {
            $table->binary('timeline')->nullable();
        });
        Schema::table('wdb_dan_plays', function (Blueprint $table) {
            $table->binary('progress')->nullable();
            $table->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::create('wdb_dan_play_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('wdb_dan_play_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 32);
            $table->string('comment', 500)->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution', 32)->nullable();
            $table->timestamps();
            $table->unique(['wdb_dan_play_id', 'reporter_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wdb_dan_play_reports');
        Schema::table('wdb_dan_plays', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hidden_by');
            $table->dropColumn('progress');
        });
        Schema::table('wdb_dan_play_songs', function (Blueprint $table) {
            $table->dropColumn('timeline');
        });
    }
};
