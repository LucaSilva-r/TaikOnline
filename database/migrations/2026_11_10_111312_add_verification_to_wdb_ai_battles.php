<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wdb_ai_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('baid')->constrained('players', 'baid')->cascadeOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->unique(['baid', 'sequence']);
            $table->char('chart_sha256', 64);
            $table->char('base_sha256', 64);
            $table->unsignedTinyInteger('course');
            $table->unsignedInteger('options');
            $table->unsignedTinyInteger('support_level');
            $table->integer('seed');
            $table->unsignedInteger('version');
            $table->boolean('ranked');
            $table->unsignedInteger('points');
            $table->unsignedInteger('wins');
            $table->double('hit_median')->nullable();
            $table->double('hit_variance')->nullable();
            $table->uuid('play_id')->nullable()->unique();
            $table->timestampsTz();
            $table->index(['baid', 'chart_sha256', 'play_id']);
        });
        Schema::table('wdb_ai_battles', function (Blueprint $table): void {
            $table->foreignUuid('session_id')->nullable()->constrained('wdb_ai_sessions')->nullOnDelete();
            $table->boolean('eligible')->default(false);
            $table->timestampTz('verified_at')->nullable();
            $table->unsignedTinyInteger('chart_level')->nullable();
            $table->json('section_counts')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('wdb_ai_battles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('session_id');
            $table->dropColumn(['eligible', 'verified_at', 'chart_level', 'section_counts']);
        });
        Schema::dropIfExists('wdb_ai_sessions');
    }
};
