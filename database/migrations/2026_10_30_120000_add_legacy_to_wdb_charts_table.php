<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A legacy chart: one Waddamburo played before it understood the chart (a 譜面分岐 chart read as its normal
     * route alone, before v0.19). It is unranked for good and its plays are shown apart, under the chart that
     * replaced it; ImportWaddamburoChartsCommand marks them from the export's legacy_sha256.
     */
    public function up(): void
    {
        Schema::table('wdb_charts', function (Blueprint $table) {
            $table->timestampTz('legacy_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('wdb_charts', function (Blueprint $table) {
            $table->dropColumn('legacy_at');
        });
    }
};
