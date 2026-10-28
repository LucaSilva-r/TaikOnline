<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Moderation (docs/features/moderation.md): sanctions on a baid (never deleted, revoking keeps the
     * history), plays hidden from every board without deleting them, and the log every moderation write
     * goes through.
     */
    public function up(): void
    {
        Schema::create('sanctions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('baid')->constrained('players', 'baid')->cascadeOnDelete();
            $table->string('type', 16);
            $table->text('reason');
            $table->text('note_to_player')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['baid', 'type']);
        });

        Schema::table('wdb_plays', function (Blueprint $table) {
            $table->timestampTz('hidden_at')->nullable();
            $table->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('hidden_reason')->nullable();
        });

        Schema::create('moderation_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 32);
            $table->foreignId('baid')->nullable()->constrained('players', 'baid')->nullOnDelete();
            $table->uuid('wdb_play_id')->nullable();
            $table->foreignId('sanction_id')->nullable()->constrained('sanctions')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->index(['baid', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_actions');
        Schema::table('wdb_plays', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hidden_by');
            $table->dropColumn(['hidden_at', 'hidden_reason']);
        });
        Schema::dropIfExists('sanctions');
    }
};
