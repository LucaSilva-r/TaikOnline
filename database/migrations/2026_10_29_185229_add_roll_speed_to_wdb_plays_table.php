<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How fast a play drummed on rolls and balloons (WdbPlay::rollSpeed), worked out once from its judgement
     * timeline when it is scored, for moderators: every raw hit on them, the fastest roll, the fastest on a
     * single pad, and the fastest a single pad was drummed evenly (a macro's mark).
     * The plays already scored with a timeline get theirs here (app:recompute-roll-speed, which also redoes
     * them after the analysis changes).
     */
    public function up(): void
    {
        Schema::table('wdb_plays', function (Blueprint $table) {
            $table->unsignedInteger('roll_hits')->nullable();
            $table->float('roll_peak_rate')->nullable();
            $table->float('roll_pad_peak_rate')->nullable();
            $table->float('roll_steady_rate')->nullable();
        });

        Artisan::call('app:recompute-roll-speed');
    }

    public function down(): void
    {
        Schema::table('wdb_plays', function (Blueprint $table) {
            $table->dropColumn(['roll_hits', 'roll_peak_rate', 'roll_pad_peak_rate', 'roll_steady_rate']);
        });
    }
};
