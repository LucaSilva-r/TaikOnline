<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Equipped title and the default tone/option settings are version-scoped
        // for the same reason costumes are: the id spaces differ per version.
        Schema::table('player_cosmetics', function (Blueprint $table): void {
            $table->string('title')->nullable()->after('game_version');
            $table->unsignedInteger('titleplate_id')->default(0)->after('title');
            $table->unsignedInteger('default_tone_setting')->default(0)->after('titleplate_id');
            $table->unsignedInteger('default_option_setting')->default(0)->after('default_tone_setting');
        });

        Schema::table('players', function (Blueprint $table): void {
            $table->dropColumn(['title', 'titleplate_id', 'default_tone_setting', 'default_option_setting']);
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table): void {
            $table->string('title')->nullable();
            $table->unsignedInteger('titleplate_id')->default(0);
            $table->unsignedInteger('default_tone_setting')->default(0);
            $table->unsignedInteger('default_option_setting')->default(0);
        });

        Schema::table('player_cosmetics', function (Blueprint $table): void {
            $table->dropColumn(['title', 'titleplate_id', 'default_tone_setting', 'default_option_setting']);
        });
    }
};
