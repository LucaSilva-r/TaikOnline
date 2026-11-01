<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Staff notifications about reports already closed: closing a report now removes them. */
    public function up(): void
    {
        DB::table('notifications')->whereIn('type', ['App\Notifications\PlayReported', 'App\Notifications\DanPlayReported'])
            ->orderBy('id')->chunk(200, function ($notifications): void {
                foreach ($notifications as $notification) {
                    $isDan = $notification->type === 'App\Notifications\DanPlayReported';
                    if (preg_match('/plays\\\\?\/([0-9a-f-]{36})/', $notification->data, $match) !== 1) {
                        continue;
                    }
                    $isOpen = DB::table($isDan ? 'wdb_dan_play_reports' : 'play_reports')
                        ->where($isDan ? 'wdb_dan_play_id' : 'wdb_play_id', $match[1])->whereNull('resolved_at')->exists();
                    if (! $isOpen) {
                        DB::table('notifications')->where('id', $notification->id)->delete();
                    }
                }
            });
    }

    public function down(): void {}
};
