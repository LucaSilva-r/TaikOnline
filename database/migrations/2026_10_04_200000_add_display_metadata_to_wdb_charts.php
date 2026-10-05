<?php

use App\Services\WaddamburoSongs;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * English titles, osu! difficulty names and osu.ppy.sh ids on charts, and songs of their own: a
     * song is a family's song id (stock and Nijiiro are one family sharing the game's ids; an osu!
     * beatmap set), and its charts come each from one or more sources (stock and Nijiiro share most
     * charts, one hash).
     */
    public function up(): void
    {
        Schema::table('wdb_charts', function (Blueprint $table) {
            $table->string('title_en')->nullable()->after('subtitle');
            $table->string('subtitle_en')->nullable()->after('title_en');
            $table->string('difficulty')->nullable()->after('level');
            $table->unsignedInteger('osu_beatmap_id')->nullable()->after('difficulty');
            $table->unsignedInteger('osu_beatmapset_id')->nullable()->after('osu_beatmap_id');
        });

        Schema::create('wdb_songs', function (Blueprint $table) {
            $table->id();
            $table->string('family', 64);
            $table->string('song_key', 600);
            $table->string('title')->nullable();
            $table->string('subtitle')->nullable();
            $table->string('title_en')->nullable();
            $table->string('subtitle_en')->nullable();
            $table->unsignedInteger('osu_beatmapset_id')->nullable();
            $table->timestamps();
            $table->unique(['family', 'song_key']);
        });

        Schema::create('wdb_chart_song', function (Blueprint $table) {
            $table->foreignId('wdb_chart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wdb_song_id')->constrained()->cascadeOnDelete();
            $table->string('source', 64);
            $table->primary(['wdb_chart_id', 'wdb_song_id', 'source']);
            $table->index('wdb_song_id');
        });

        // Charts from before songs had keys: grouped by title (re-importing gives them their real songs).
        DB::table('wdb_charts')->whereNotNull('title')->orderBy('id')->chunk(1000, function ($charts): void {
            WaddamburoSongs::attach($charts->map(fn (object $chart): array => [
                'wdb_chart_id' => $chart->id, ...(array) $chart,
            ])->all());
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wdb_chart_song');
        Schema::dropIfExists('wdb_songs');
        Schema::table('wdb_charts', function (Blueprint $table) {
            $table->dropColumn(['title_en', 'subtitle_en', 'difficulty', 'osu_beatmap_id', 'osu_beatmapset_id']);
        });
    }
};
