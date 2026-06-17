<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Head/body part ids the avatar was composited from when no kigurumi is worn
     * (avatar_costume is 0).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedInteger('avatar_head')->nullable()->after('avatar_costume');
            $table->unsignedInteger('avatar_body')->nullable()->after('avatar_head');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['avatar_head', 'avatar_body']);
        });
    }
};
