<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether a Waddamburo chart is official, as the chart import marks it: shipped with unmodified
     * Green or Nijiiro, or a song the Nijiiro cabinet had at some point (removed ones, later ones).
     * Everything else (TJA, osu!, console songs added to a Nijiiro install) stays unranked and off
     * the public pages unless an admin ranks it.
     */
    public function up(): void
    {
        Schema::table('wdb_charts', function (Blueprint $table) {
            $table->boolean('is_stock')->default(false)->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('wdb_charts', function (Blueprint $table) {
            $table->dropColumn('is_stock');
        });
    }
};
