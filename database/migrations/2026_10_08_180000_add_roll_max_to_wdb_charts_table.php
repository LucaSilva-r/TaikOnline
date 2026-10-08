<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The most drumroll hits a Waddamburo chart allows (one per 1/60 s of its rolls), beside a play's rolls.
     * Filled by the scorer the first time one of its plays is scored; null until then.
     */
    public function up(): void
    {
        Schema::table('wdb_charts', function (Blueprint $table) {
            $table->unsignedInteger('roll_max')->nullable()->after('level');
        });
    }

    public function down(): void
    {
        Schema::table('wdb_charts', function (Blueprint $table) {
            $table->dropColumn('roll_max');
        });
    }
};
