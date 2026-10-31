<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The moderation log names the dan run an action was on, as it does a song play. */
    public function up(): void
    {
        Schema::table('moderation_actions', function (Blueprint $table) {
            $table->foreignUuid('wdb_dan_play_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('moderation_actions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wdb_dan_play_id');
        });
    }
};
