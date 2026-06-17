<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Don customization a user's profile picture was generated from, so the avatar
     * customizer can reload their look instead of resetting to defaults on refresh.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedInteger('avatar_costume')->nullable()->after('avatar_updated_at');
            $table->unsignedInteger('avatar_color_face')->nullable()->after('avatar_costume');
            $table->unsignedInteger('avatar_color_body')->nullable()->after('avatar_color_face');
            $table->unsignedInteger('avatar_color_limb')->nullable()->after('avatar_color_body');
            $table->string('avatar_face')->nullable()->after('avatar_color_limb');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'avatar_costume',
                'avatar_color_face',
                'avatar_color_body',
                'avatar_color_limb',
                'avatar_face',
            ]);
        });
    }
};
