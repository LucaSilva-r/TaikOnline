<?php

use App\Models\Player;
use App\Models\User;
use App\Models\WdbChart;
use App\Models\WdbPlay;
use App\Models\WdbSong;
use App\Services\WaddamburoRankAggregateService;
use App\Services\WaddamburoSongs;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function wdb_web_player(): Player
{
    $user = User::factory()->create();

    return Player::query()->create(['user_id' => $user->id, 'mydon_name' => 'どん'])->fresh('user');
}

/** A chart in its song (by title unless a song key is given), as uploads and imports file it. */
function wdb_web_chart(array $attributes, ?string $songKey = null): WdbChart
{
    $chart = WdbChart::query()->create($attributes);
    WaddamburoSongs::attach([['wdb_chart_id' => $chart->id, 'song_key' => $songKey, ...$attributes]]);

    return $chart;
}

function wdb_web_play(Player $player, WdbChart $chart, int $score, bool $cleared = true, int $good = 5, int $miss = 0): void
{
    WdbPlay::query()->create([
        'id' => (string) Str::uuid(), 'baid' => $player->baid, 'wdb_chart_id' => $chart->id, 'mode' => 'normal',
        'course' => $chart->course, 'score' => $score, 'great' => 100, 'good' => $good, 'miss' => $miss,
        'max_combo' => 100, 'rolls' => 0, 'gauge' => 40, 'cleared' => $cleared, 'scoring_version' => 1,
        'engine_version' => 'test', 'played_at' => now(), 'replay' => 'inputs',
    ]);
}

it('shows Waddamburo songs, leaderboards and boards, counting only ranked charts in the rankings', function (): void {
    $player = wdb_web_player();
    $oni = wdb_web_chart(['sha256' => str_repeat('a', 64), 'title' => 'Song', 'source' => 'TJA', 'course' => 3]);
    $hard = wdb_web_chart(['sha256' => str_repeat('b', 64), 'title' => 'Song', 'source' => 'TJA', 'course' => 2]);
    wdb_web_play($player, $oni, 800000);
    wdb_web_play($player, $oni, 900000, good: 0);
    wdb_web_play($player, $hard, 700000, cleared: false);

    $this->get('/waddamburo/songs')->assertSuccessful()->assertInertia(fn (Assert $page) => $page
        ->component('Songs')->where('gameVersion.value', 'waddamburo')->has('songs.data', 1)
        ->where('songs.data.0.play_count', 3));
    $this->get("/waddamburo/songs/{$oni->songs()->value('wdb_songs.id')}")->assertSuccessful()->assertInertia(fn (Assert $page) => $page
        ->component('SongDetail')->where('song.title', 'Song')->has('difficulties', 2)
        ->where('difficulties.1.level', 4)->where('difficulties.1.entries.0.score', 900000)
        ->where('difficulties.1.entries.0.crown', 3));

    // Nothing is ranked yet: no standings, and the board keeps unranked plays for the owner only.
    $this->get('/waddamburo/rankings')->assertInertia(fn (Assert $page) => $page->has('entries', 0));
    $this->get("/waddamburo/users/{$player->user_id}/board")->assertInertia(fn (Assert $page) => $page
        ->has('bestPerformances', 0));
    $this->actingAs($player->user)->get("/waddamburo/users/{$player->user_id}/board")
        ->assertInertia(fn (Assert $page) => $page->has('bestPerformances', 2)
            ->where('bestPerformances.0.counts_for_leaderboard', false));

    $oni->update(['ranked_at' => now()]);
    app(WaddamburoRankAggregateService::class)->recomputeChart($oni->id);
    $this->get('/waddamburo/rankings')->assertInertia(fn (Assert $page) => $page
        ->component('Rankings')->has('entries', 1)->where('entries.0.total_score', 900000)
        ->where('entries.0.crown_counts.dondaful', 1));
});

it('no longer serves the retired Extra pages', function (): void {
    $this->get('/extra/rankings')->assertNotFound();
});

it('lets admins rank a chart, which recomputes its players\' standings', function (): void {
    $admin = User::factory()->create(['role' => 'admin']);
    $player = wdb_web_player();
    $chart = wdb_web_chart(['sha256' => str_repeat('c', 64), 'title' => 'Ranked', 'course' => 3]);
    wdb_web_play($player, $chart, 850000);

    $this->actingAs($admin)->get('/waddamburo/admin/waddamburo-charts')->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('admin/WaddamburoCharts')->where('songs.data.0.charts.0.ranked', false));
    $this->actingAs($admin)->patch("/waddamburo/admin/waddamburo-charts/{$chart->id}", ['ranked' => true])->assertRedirect();

    expect($chart->fresh()->ranked_at)->not->toBeNull();
    $this->get('/waddamburo/rankings')->assertInertia(fn (Assert $page) => $page->where('entries.0.total_score', 850000));

    $this->actingAs($player->user)->patch("/waddamburo/admin/waddamburo-charts/{$chart->id}", ['ranked' => false])->assertForbidden();
});

it('lists Waddamburo songs with their charts for ranking, filtered by source and ranking state', function (): void {
    $admin = User::factory()->create(['role' => 'admin']);
    $player = wdb_web_player();
    $hard = wdb_web_chart(['sha256' => str_repeat('d', 64), 'title' => 'Song', 'source' => 'Stock', 'course' => 2, 'level' => 5], 'song');
    $oni = wdb_web_chart(['sha256' => str_repeat('e', 64), 'title' => 'Song', 'source' => 'Stock', 'course' => 3, 'level' => 8, 'ranked_at' => now()], 'song');
    $custom = wdb_web_chart(['sha256' => str_repeat('f', 64), 'title' => 'Custom', 'source' => 'Tja', 'course' => 3]);
    WdbChart::query()->create(['sha256' => str_repeat('0', 64)]);
    wdb_web_play($player, $oni, 850000);
    $song = $hard->songs()->value('wdb_songs.id');

    $this->actingAs($admin)->get('/waddamburo/admin/waddamburo-charts?source=Stock')->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('admin/WaddamburoCharts')
            ->where('sources', ['Stock', 'Tja'])->where('unlinkedCharts', 1)
            ->has('songs.data', 1)->where('songs.data.0.id', $song)
            ->where('songs.data.0.chart_count', 2)->where('songs.data.0.ranked_count', 1)->where('songs.data.0.plays_count', 1)
            ->where('songs.data.0.charts.0.id', $hard->id)->where('songs.data.0.charts.1.ranked', true));
    $this->actingAs($admin)->get('/waddamburo/admin/waddamburo-charts?ranked=none')
        ->assertInertia(fn (Assert $page) => $page->has('songs.data', 1)->where('songs.data.0.title', 'Custom'));
    $this->actingAs($admin)->get('/waddamburo/admin/waddamburo-charts?q='.str_repeat('d', 8))
        ->assertInertia(fn (Assert $page) => $page->has('songs.data', 1)->where('songs.data.0.id', $song));

    // Ranking the song ranks every chart of it, and its plays count.
    $this->actingAs($admin)->patch("/waddamburo/admin/waddamburo-songs/{$song}", ['ranked' => true])->assertRedirect();
    expect($hard->fresh()->ranked_at)->not->toBeNull()->and($custom->fresh()->ranked_at)->toBeNull();
    $this->get('/waddamburo/rankings')->assertInertia(fn (Assert $page) => $page->where('entries.0.total_score', 850000));
    $this->actingAs($player->user)->patch("/waddamburo/admin/waddamburo-songs/{$song}", ['ranked' => false])->assertForbidden();
});

it('imports and ranks exported stock and Nijiiro charts, skipping lines whose notes do not match', function (): void {
    $player = wdb_web_player();
    $notes = 'WDBC canonical notes';
    $sha256 = hash('sha256', $notes);
    // Uploaded before songs had keys, with a subtitle another machine's table did not have.
    $played = wdb_web_chart(['sha256' => $sha256, 'title' => '千本桜', 'subtitle' => '', 'source' => 'Stock', 'course' => 3]);
    wdb_web_play($player, $played, 900000);
    $line = fn (string $sha, string $source, string $key, ?string $subtitle = null): string => json_encode([
        'sha256' => $sha, 'notes' => base64_encode(gzencode($notes)), 'title' => '千本桜', 'song_key' => $key,
        'subtitle' => $subtitle, 'title_en' => 'Senbonzakura', 'source' => $source, 'course' => 3, 'level' => 9,
    ]);
    $export = tempnam(sys_get_temp_dir(), 'wdb');
    file_put_contents($export, gzencode(implode("\n", [
        $line($sha256, 'Stock', 'senbon', '黒うさP feat.初音ミク'),
        $line($sha256, 'Nijiiro', 'senbon'), // the same chart in Nijiiro
        $line(str_repeat('9', 64), 'Stock', 'forged'),
    ])."\n"));

    $this->artisan('app:import-waddamburo-charts', ['file' => $export])
        ->expectsOutput('Imported and ranked 2 charts (1 invalid lines skipped).')->assertSuccessful();

    expect(WdbChart::query()->count())->toBe(1)
        ->and($played->fresh())->level->toBe(9)->ranked_at->not->toBeNull()->title_en->toBe('Senbonzakura')
        // One song shipping the chart from both sources; the title-grouped song it came with is gone.
        ->and(WdbSong::query()->count())->toBe(1)
        ->and($played->songs()->orderBy('source')->pluck('source')->all())->toBe(['Nijiiro', 'Stock'])
        ->and(WdbSong::query()->value('subtitle'))->toBe('黒うさP feat.初音ミク');
    $this->get('/waddamburo/rankings')->assertInertia(fn (Assert $page) => $page->where('entries.0.total_score', 900000));
    $this->get('/waddamburo/songs?q=senbon')->assertInertia(fn (Assert $page) => $page->has('songs.data', 1)
        ->where('songs.data.0.play_count', 1)->where('songs.data.0.genre.label', 'Stock · Nijiiro'));
    $this->get('/waddamburo/songs?source=Nijiiro')->assertInertia(fn (Assert $page) => $page->has('songs.data', 1));
});

it('edits the Green Don-chan from the Waddamburo scope', function (): void {
    $player = wdb_web_player();

    $this->actingAs($player->user)->get('/waddamburo/settings/costumes')->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('settings/DonChan')->where('supported', true)
            ->where('taikoVersion.current.supports.costumeSlots', true));
});

it('finds songs by English title, filters by source and names osu! difficulties with their osu! links', function (): void {
    $player = wdb_web_player();
    $stock = wdb_web_chart(['sha256' => str_repeat('1', 64), 'title' => '千本桜', 'title_en' => 'Senbonzakura',
        'subtitle' => '黒うさP feat.初音ミク', 'source' => 'Stock', 'course' => 3]);
    $osu = wdb_web_chart(['sha256' => str_repeat('2', 64), 'title' => 'Osu Song', 'source' => 'OsuLazer',
        'course' => 3, 'difficulty' => 'Inner Oni', 'osu_beatmap_id' => 123, 'osu_beatmapset_id' => 45], 'set:45');
    wdb_web_play($player, $stock, 800000);
    wdb_web_play($player, $osu, 700000);

    $this->get('/waddamburo/songs?q=senbon')->assertInertia(fn (Assert $page) => $page
        ->has('songs.data', 1)->where('songs.data.0.title_en', 'Senbonzakura')
        ->where('songs.data.0.genre.label', 'Stock')->has('sources', 4));
    // Ranked songs are listed before anyone plays them.
    wdb_web_chart(['sha256' => str_repeat('3', 64), 'title' => 'Unplayed', 'source' => 'Nijiiro', 'course' => 3, 'ranked_at' => now()]);
    $this->get('/waddamburo/songs?source=Nijiiro')->assertInertia(fn (Assert $page) => $page
        ->has('songs.data', 1)->where('songs.data.0.title', 'Unplayed'));
    $this->get('/waddamburo/songs?source=OsuLazer')->assertInertia(fn (Assert $page) => $page
        ->has('songs.data', 1)->where('songs.data.0.genre.label', 'osu!lazer')->where('filters.source', 'OsuLazer'));
    $this->get("/waddamburo/songs/{$osu->songs()->value('wdb_songs.id')}")->assertInertia(fn (Assert $page) => $page
        ->where('song.external_url', 'https://osu.ppy.sh/beatmapsets/45')
        ->where('difficulties.0.name', 'Inner Oni')
        ->where('difficulties.0.external_url', 'https://osu.ppy.sh/beatmapsets/45#taiko/123')
        ->where('recentPlays.0.difficulty', 'Inner Oni'));
});

it('merges stock and Nijiiro songs by song id, marking the charts only one of them ships', function (): void {
    $stockOni = wdb_web_chart(['sha256' => str_repeat('4', 64), 'title' => 'Ｄｒｅａｄｎｏｕｇｈｔ', 'source' => 'Stock', 'course' => 3, 'ranked_at' => now()], 'dreadn');
    WaddamburoSongs::attach([['wdb_chart_id' => $stockOni->id, 'song_key' => 'dreadn', 'source' => 'Nijiiro', 'title' => 'Dreadnought']]);
    wdb_web_chart(['sha256' => str_repeat('5', 64), 'title' => 'Dreadnought', 'source' => 'Nijiiro', 'course' => 4, 'ranked_at' => now()], 'dreadn');

    $song = WdbSong::query()->sole();
    expect($song->title)->toBe('Dreadnought');
    $this->get("/waddamburo/songs/{$song->id}")->assertInertia(fn (Assert $page) => $page
        ->where('song.sources', ['Stock', 'Nijiiro'])->has('difficulties', 2)
        ->where('difficulties.0.sources', ['Stock', 'Nijiiro'])
        ->where('difficulties.1.sources', ['Nijiiro']));
});
