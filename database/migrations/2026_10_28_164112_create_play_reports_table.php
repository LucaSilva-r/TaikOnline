<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Players' reports of Waddamburo plays, the moderators' review queue: one per play and reporter, closed
     * as dismissed, play hidden or player sanctioned (docs/features/moderation.md).
     */
    public function up(): void
    {
        Schema::create('play_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('wdb_play_id')->constrained('wdb_plays')->cascadeOnDelete();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 32);
            $table->text('comment')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution', 32)->nullable();
            $table->timestamps();
            $table->unique(['wdb_play_id', 'reporter_id']);
            $table->index('resolved_at');
        });
        Schema::table('moderation_actions', function (Blueprint $table) {
            $table->foreignId('play_report_id')->nullable()->constrained('play_reports')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('moderation_actions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('play_report_id');
        });
        Schema::dropIfExists('play_reports');
    }
};
