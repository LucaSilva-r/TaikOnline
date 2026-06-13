<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_shop_season_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('baid')->constrained('players', 'baid')->cascadeOnDelete();
            $table->string('game_version');
            $table->unsignedInteger('season_id');
            $table->unsignedInteger('total_get_donmedal')->default(0);
            $table->unsignedInteger('total_use_donmedal')->default(0);
            $table->timestampsTz();

            $table->unique(['baid', 'game_version', 'season_id']);
        });

        Schema::create('player_shop_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('baid')->constrained('players', 'baid')->cascadeOnDelete();
            $table->string('game_version');
            $table->unsignedInteger('season_id');
            $table->unsignedInteger('item_type');
            $table->unsignedInteger('item_id');
            $table->unsignedInteger('item_no');
            $table->unsignedInteger('item_price');
            $table->timestampTz('purchased_at')->useCurrent();
            $table->timestampsTz();

            $table->unique(['baid', 'game_version', 'season_id', 'item_type', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_shop_items');
        Schema::dropIfExists('player_shop_season_states');
    }
};
