<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The hash of a play's inputs, to find another player's copy of them on the chart. */
    public function up(): void
    {
        Schema::table('wdb_plays', function (Blueprint $table): void {
            $table->char('replay_hash', 64)->nullable();
            $table->index(['wdb_chart_id', 'replay_hash']);
        });

        DB::table('wdb_plays')->select(['id', 'replay'])->orderBy('id')->chunk(200, function ($plays): void {
            foreach ($plays as $play) {
                $replay = is_resource($play->replay) ? stream_get_contents($play->replay) : $play->replay;
                $inputs = @gzdecode((string) $replay);
                if ($inputs !== false) {
                    DB::table('wdb_plays')->where('id', $play->id)->update(['replay_hash' => hash('sha256', $inputs)]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('wdb_plays', function (Blueprint $table): void {
            $table->dropIndex(['wdb_chart_id', 'replay_hash']);
            $table->dropColumn('replay_hash');
        });
    }
};
