<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Waddamburo's earned titles held the official titles players wore on the website (wearing added them), which
     * unlocked those titles' items. Titles are earned by playing from now on (WaddamburoTitles), so the list starts
     * empty. The worn title itself (title, titleplate_id) stays.
     */
    public function up(): void
    {
        DB::table('player_cosmetics')->where('game_version', 'waddamburo')->update(['unlocked_titles' => '[]']);
    }

    /** Not reversible: which titles were worn is gone. */
    public function down(): void {}
};
