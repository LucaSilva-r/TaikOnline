<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table): void {
            $table->unsignedInteger('item_shop_tutorial_flg')->default(0)->after('disp_dan_type');
            $table->unsignedInteger('waiwai_tutorial_flg')->default(0)->after('item_shop_tutorial_flg');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('players', function (Blueprint $table): void {
            $table->dropColumn(['item_shop_tutorial_flg', 'waiwai_tutorial_flg']);
        });
    }
};
