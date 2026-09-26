<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Presence flag + cache-buster for the generated Don-chan avatar PNG stored at
     * storage/app/public/avatars/{user_id}.png. Null means no avatar generated yet,
     * so the UI falls back to initials.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('avatar_updated_at')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('avatar_updated_at');
        });
    }
};
