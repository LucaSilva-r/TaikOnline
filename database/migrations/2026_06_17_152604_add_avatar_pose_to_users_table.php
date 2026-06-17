<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('avatar_animation')->nullable()->after('avatar_face_frame');
            $table->double('avatar_animation_frame')->nullable()->after('avatar_animation');
            $table->double('avatar_camera_yaw')->nullable()->after('avatar_animation_frame');
            $table->double('avatar_camera_pitch')->nullable()->after('avatar_camera_yaw');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'avatar_animation',
                'avatar_animation_frame',
                'avatar_camera_yaw',
                'avatar_camera_pitch',
            ]);
        });
    }
};
