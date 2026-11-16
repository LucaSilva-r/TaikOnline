<?php

use App\Services\WaddamburoSongs;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wii and Wii U songs join the game's songs: a chart link records its release ("wii4", "wiiu2"; empty for the other
 * sources), and the songs the Wii family had ("wiiu3:anp") move to the Namco song of their id ("anp").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wdb_chart_song', function (Blueprint $table) {
            $table->string('release', 16)->default('');
        });
        Schema::table('wdb_chart_song', function (Blueprint $table) {
            $table->dropPrimary(['wdb_chart_id', 'wdb_song_id', 'source']);
            $table->primary(['wdb_chart_id', 'wdb_song_id', 'source', 'release']);
        });

        $links = DB::table('wdb_chart_song')
            ->join('wdb_songs', 'wdb_songs.id', '=', 'wdb_chart_song.wdb_song_id')
            ->where('wdb_songs.family', 'Wii')
            ->get(['wdb_chart_song.wdb_chart_id', 'wdb_chart_song.wdb_song_id', 'wdb_songs.song_key', 'wdb_songs.title',
                'wdb_songs.subtitle', 'wdb_songs.title_en', 'wdb_songs.subtitle_en']);
        foreach ($links->chunk(1000) as $chunk) {
            DB::table('wdb_chart_song')->where('source', 'Wii')->whereIn('wdb_song_id', $chunk->pluck('wdb_song_id')->unique())->delete();
            WaddamburoSongs::attach($chunk->map(fn (object $link): array => [
                'wdb_chart_id' => $link->wdb_chart_id, 'source' => 'Wii', 'song_key' => $link->song_key,
                'title' => $link->title, 'subtitle' => $link->subtitle, 'title_en' => $link->title_en, 'subtitle_en' => $link->subtitle_en,
            ])->values()->all());
        }
        // attach drops songs left without charts; a Wii song whose chart is gone too.
        DB::table('wdb_songs')->where('family', 'Wii')->whereNotExists(fn ($query) => $query->from('wdb_chart_song')
            ->whereColumn('wdb_chart_song.wdb_song_id', 'wdb_songs.id'))->delete();
    }

    public function down(): void
    {
        // ponytail: the moved links stay in their Namco songs; only the column goes.
        DB::table('wdb_chart_song')->where('release', '<>', '')->whereIn('source', ['Wii', 'WiiU'])
            ->orderBy('wdb_chart_id')->get()->groupBy(fn (object $link): string => $link->wdb_chart_id."\0".$link->wdb_song_id."\0".$link->source)
            ->each(fn ($group) => $group->skip(1)->each(fn (object $link) => DB::table('wdb_chart_song')
                ->where(['wdb_chart_id' => $link->wdb_chart_id, 'wdb_song_id' => $link->wdb_song_id, 'source' => $link->source, 'release' => $link->release])->delete()));
        Schema::table('wdb_chart_song', function (Blueprint $table) {
            $table->dropPrimary(['wdb_chart_id', 'wdb_song_id', 'source', 'release']);
            $table->primary(['wdb_chart_id', 'wdb_song_id', 'source']);
        });
        Schema::table('wdb_chart_song', function (Blueprint $table) {
            $table->dropColumn('release');
        });
    }
};
