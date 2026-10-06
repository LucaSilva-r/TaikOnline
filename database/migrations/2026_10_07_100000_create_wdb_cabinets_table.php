<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Waddamburo cabinets an admin let in: each has its own token (only its hash is kept) and can be cut
     * off by revoking it. Plays remember the cabinet that sent them.
     */
    public function up(): void
    {
        Schema::create('wdb_cabinets', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::table('wdb_plays', function (Blueprint $table) {
            $table->foreignId('wdb_cabinet_id')->nullable()->after('seed')->constrained('wdb_cabinets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wdb_plays', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wdb_cabinet_id');
        });
        Schema::dropIfExists('wdb_cabinets');
    }
};
