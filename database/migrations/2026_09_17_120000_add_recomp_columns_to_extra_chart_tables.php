<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TaikoRecomp's Taiko+ mode plays the same imported charts as Zucchini, so its
 * scores belong on the same Extra board rather than on a thirteenth one. Two
 * differences need recording:
 *
 * - It keys a chart by the hash of the *source* file (.tja, .osu, installed
 *   Nijiiro chart) while Zucchini keys by the converted Green fumen, so the
 *   same song arrives as two chart rows. source_sha256/source_kind make that
 *   visible, and extra_songs is where the two can later be grouped.
 * - Its gameplay could diverge from Zucchini's, so every play result records
 *   which client produced it. That is the escape hatch for splitting or
 *   filtering the board later without migrating history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('extra_charts', function (Blueprint $table): void {
            $table->string('source_kind')->nullable()->after('difficulty');
            $table->char('source_sha256', 64)->nullable()->after('source_kind');
            $table->index('source_sha256');
        });

        Schema::table('extra_chart_play_results', function (Blueprint $table): void {
            $table->string('client')->default('zucchini')->after('origin_game_version');
            $table->index(['client', 'played_at']);
        });
    }

    public function down(): void
    {
        Schema::table('extra_chart_play_results', function (Blueprint $table): void {
            $table->dropIndex(['client', 'played_at']);
            $table->dropColumn('client');
        });

        Schema::table('extra_charts', function (Blueprint $table): void {
            $table->dropIndex(['source_sha256']);
            $table->dropColumn(['source_kind', 'source_sha256']);
        });
    }
};
