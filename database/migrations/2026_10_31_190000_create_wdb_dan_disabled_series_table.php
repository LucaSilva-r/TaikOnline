<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The dan series an admin stopped serving. Nijiiro's start that way, until its charts are imported. */
    public function up(): void
    {
        Schema::create('wdb_dan_disabled_series', function (Blueprint $table) {
            $table->string('series')->primary();
            $table->timestamps();
        });
        foreach (range(2020, 2026) as $year) {
            DB::table('wdb_dan_disabled_series')->insert(['series' => "nijiiro-{$year}", 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wdb_dan_disabled_series');
    }
};
