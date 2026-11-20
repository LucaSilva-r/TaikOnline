<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Katsu Medals a credit gave (Green: 100 a play, none for a dan course); they never reset, unlike Don Medals. */
    public function up(): void
    {
        Schema::table('wdb_medal_credits', function (Blueprint $table) {
            $table->unsignedInteger('katsu')->default(0);
        });
        // ponytail: credits paid before Katsu Medals all count as plays (a dan credit gets 100 too).
        DB::table('wdb_medal_credits')->update(['katsu' => 100]);
    }

    public function down(): void
    {
        Schema::table('wdb_medal_credits', function (Blueprint $table) {
            $table->dropColumn('katsu');
        });
    }
};
