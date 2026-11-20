<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A song's drum (0 = 1P) and Song Select's timer when it was picked (arcade only): some condition titles ask for them. */
    public function up(): void
    {
        Schema::table('wdb_medal_songs', function (Blueprint $table) {
            $table->unsignedTinyInteger('side')->default(0);
            $table->unsignedSmallInteger('select_seconds')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('wdb_medal_songs', function (Blueprint $table) {
            $table->dropColumn(['side', 'select_seconds']);
        });
    }
};
