<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The audio and input offsets a Waddamburo play was made with (context for its replay; null before they were sent). */
    public function up(): void
    {
        Schema::table('wdb_plays', function (Blueprint $table) {
            $table->smallInteger('audio_offset_ms')->nullable()->after('engine_version');
            $table->smallInteger('input_offset_ms')->nullable()->after('audio_offset_ms');
        });
    }

    public function down(): void
    {
        Schema::table('wdb_plays', function (Blueprint $table) {
            $table->dropColumn(['audio_offset_ms', 'input_offset_ms']);
        });
    }
};
