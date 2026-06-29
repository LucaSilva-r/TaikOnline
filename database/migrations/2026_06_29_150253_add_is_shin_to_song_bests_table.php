<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('song_bests', function (Blueprint $table): void {
            $table->dropUnique(['baid', 'game_version', 'song_no', 'level']);
            $table->boolean('is_shin')->default(false)->after('level');
            $table->unique(['baid', 'game_version', 'song_no', 'level', 'is_shin']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('song_bests', function (Blueprint $table): void {
            $table->dropUnique(['baid', 'game_version', 'song_no', 'level', 'is_shin']);
            $table->dropColumn('is_shin');
            $table->unique(['baid', 'game_version', 'song_no', 'level']);
        });
    }
};
