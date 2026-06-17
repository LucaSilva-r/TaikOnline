<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedInteger('avatar_puchi')->nullable()->after('avatar_body');
            $table->unsignedTinyInteger('avatar_puchi_frame')->nullable()->after('avatar_puchi');
            $table->double('avatar_puchi_x')->nullable()->after('avatar_puchi_frame');
            $table->double('avatar_puchi_y')->nullable()->after('avatar_puchi_x');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'avatar_puchi',
                'avatar_puchi_frame',
                'avatar_puchi_x',
                'avatar_puchi_y',
            ]);
        });
    }
};
