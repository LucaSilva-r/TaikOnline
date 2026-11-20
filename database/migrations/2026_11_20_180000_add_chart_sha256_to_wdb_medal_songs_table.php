<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A finished song's chart (failed ones too): the credit's condition titles look at its songs. */
    public function up(): void
    {
        Schema::table('wdb_medal_songs', function (Blueprint $table) {
            $table->char('chart_sha256', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('wdb_medal_songs', function (Blueprint $table) {
            $table->dropColumn('chart_sha256');
        });
    }
};
