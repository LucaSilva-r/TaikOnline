<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('song_play_results', function (Blueprint $table): void {
            $table->json('ghost_sections')->nullable()->after('raw_stage');
        });
    }

    public function down(): void
    {
        Schema::table('song_play_results', function (Blueprint $table): void {
            $table->dropColumn('ghost_sections');
        });
    }
};
