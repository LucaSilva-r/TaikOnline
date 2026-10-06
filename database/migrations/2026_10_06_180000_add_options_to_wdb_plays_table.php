<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Waddamburo play's options (bits, one per chosen item: 2 真打, 4/8/16 speed range x2/x3/x4, 32 ドロン,
     * 64 あべこべ, 128 きまぐれ, 256 でたらめ; from bit 9 the exact speed's index in Nijiiro's 1.0-4.0 list) and
     * the random options' seed, so its replay gets the same notes.
     */
    public function up(): void
    {
        Schema::table('wdb_plays', function (Blueprint $table) {
            $table->unsignedSmallInteger('options')->default(0)->after('input_offset_ms');
            $table->integer('seed')->nullable()->after('options');
        });
    }

    public function down(): void
    {
        Schema::table('wdb_plays', function (Blueprint $table) {
            $table->dropColumn(['options', 'seed']);
        });
    }
};
