<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The engine's judgement timeline of a play (gzipped JSON, ~20 KB), kept from its rescore for the
     * play page and replay player. Null until the play is rescored by a scorer that returns it.
     */
    public function up(): void
    {
        Schema::table('wdb_plays', function (Blueprint $table) {
            $table->binary('timeline')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('wdb_plays', function (Blueprint $table) {
            $table->dropColumn('timeline');
        });
    }
};
