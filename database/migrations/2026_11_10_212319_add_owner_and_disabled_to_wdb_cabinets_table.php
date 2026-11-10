<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Players can issue their own cabinet tokens once they accept the cabinet terms. A token belongs to
     * its user (off while that user is banned, gone with their account); admins can disable one for a while (disabled_at) or
     * revoke it for good (revoked_at).
     */
    public function up(): void
    {
        Schema::table('wdb_cabinets', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('token_hash')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('disabled_at')->nullable()->after('last_seen_at');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->timestampTz('cabinet_terms_accepted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('cabinet_terms_accepted_at');
        });
        Schema::table('wdb_cabinets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn('disabled_at');
        });
    }
};
