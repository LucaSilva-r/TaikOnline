<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_cosmetics', function (Blueprint $table): void {
            // The three きせかえセット presets, each a {costume_1,2,3,5} part set,
            // sent to the cabinet as ary_favorite_costumedata. The active preset
            // is mirrored into the equipped costume_1..5 columns.
            $table->json('costume_presets')->default('[]');
            $table->unsignedTinyInteger('active_costume_preset')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('player_cosmetics', function (Blueprint $table): void {
            $table->dropColumn(['costume_presets', 'active_costume_preset']);
        });
    }
};
