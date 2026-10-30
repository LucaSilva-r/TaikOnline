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
        'engine_version' => 'test', 'played_at' => now(), 'replay' => 'inputs', 'rescored_at' => now(),
    ]);
}

it('shows Waddamburo songs, leaderboards and boards, counting only ranked charts in the rankings', function (): void {
    $player = wdb_web_player();
    $oni = wdb_web_chart(['sha256' => str_repeat('a', 64), 'title' => 'Song', 'source' => 'TJA', 'course' => 3]);
    $hard = wdb_web_chart(['sha256' => str_repeat('b', 64), 'title' => 'Song', 'source' => 'TJA', 'course' => 2]);
    wdb_web_play($player, $oni, 800000);
    wdb_web_play($player, $oni, 900000, good: 0);
    wdb_web_play($player, $hard, 700000, cleared: false);

    $this->actingAs($player->user)->get('/waddamburo/songs')->assertSuccessful()->assertInertia(fn (Assert $page) => $page
        ->component('Songs')->where('gameVersion.value', 'waddamburo')->has('songs.data', 1)
        ->where('songs.data.0.play_count', 3));
    $this->actingAs($player->user)->get("/waddamburo/songs/{$oni->songs()->value('wdb_songs.id')}")->assertSuccessful()->assertInertia(fn (Assert $page) => $page
        ->component('SongDetail')->where('song.title', 'Song')->has('difficulties', 2)
        ->where('difficulties.1.level', 4)->where('difficulties.1.entries.0.score', 900000)
        ->where('difficulties.1.entries.0.crown', 3)
        // The best play's own counts (its 900000 had no Ok).
        ->where('difficulties.1.entries.0.counts.good', 100)->where('difficulties.1.entries.0.counts.ok', 0)
        ->where('difficulties.1.entries.0.counts.combo', 100));

    // Nothing is ranked yet: no standings, and the board keeps unranked plays for the owner only.
    auth()->logout();
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

    $this->artisan('app:import-waddamburo-charts', ['files' => [$export]])
        ->expectsOutput('Imported and ranked 2 charts (1 invalid lines skipped).')->assertSuccessful();

    expect(WdbChart::query()->count())->toBe(1)
        ->and($played->fresh())->level->toBe(9)->ranked_at->not->toBeNull()->is_stock->toBeTrue()->title_en->toBe('Senbonzakura')
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
        'subtitle' => '黒うさP feat.初音ミク', 'source' => 'Stock', 'is_stock' => true, 'course' => 3]);
    $osu = wdb_web_chart(['sha256' => str_repeat('2', 64), 'title' => 'Osu Song', 'source' => 'OsuLazer',
        'course' => 3, 'difficulty' => 'Inner Oni', 'osu_beatmap_id' => 123, 'osu_beatmapset_id' => 45], 'set:45');
    wdb_web_play($player, $stock, 800000);
    wdb_web_play($player, $osu, 700000);

    $this->actingAs($player->user);
    $this->get('/waddamburo/songs?q=senbon')->assertInertia(fn (Assert $page) => $page
        ->has('songs.data', 1)->where('songs.data.0.title_en', 'Senbonzakura')
        ->where('songs.data.0.genre.label', 'Stock')->has('sources', 4));
    // Ranked songs are listed before anyone plays them.
    wdb_web_chart(['sha256' => str_repeat('3', 64), 'title' => 'Unplayed', 'source' => 'Nijiiro', 'is_stock' => true, 'course' => 3, 'ranked_at' => now()]);
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
    $stockOni = wdb_web_chart(['sha256' => str_repeat('4', 64), 'title' => 'Ｄｒｅａｄｎｏｕｇｈｔ', 'source' => 'Stock', 'is_stock' => true, 'course' => 3, 'ranked_at' => now()], 'dreadn');
    WaddamburoSongs::attach([['wdb_chart_id' => $stockOni->id, 'song_key' => 'dreadn', 'source' => 'Nijiiro', 'title' => 'Dreadnought']]);
    wdb_web_chart(['sha256' => str_repeat('5', 64), 'title' => 'Dreadnought', 'source' => 'Nijiiro', 'is_stock' => true, 'course' => 4, 'ranked_at' => now()], 'dreadn');

    $song = WdbSong::query()->sole();
    expect($song->title)->toBe('Dreadnought');
    $this->get("/waddamburo/songs/{$song->id}")->assertInertia(fn (Assert $page) => $page
        ->where('song.sources', ['Stock', 'Nijiiro'])->has('difficulties', 2)
        ->where('difficulties.0.sources', ['Stock', 'Nijiiro'])
        ->where('difficulties.1.sources', ['Nijiiro']));
});

it('shows custom charts only to the players who played them, with everyone\'s scores on them', function (): void {
    $player = wdb_web_player();
    $rival = wdb_web_player();
    $stranger = wdb_web_player();
    $stock = wdb_web_chart(['sha256' => str_repeat('6', 64), 'title' => 'Official', 'source' => 'Nijiiro', 'course' => 3,
        'is_stock' => true, 'ranked_at' => now()], 'offic');
    // A chart added to the same song by a modded install, and a song of custom charts only.
    $extra = wdb_web_chart(['sha256' => str_repeat('7', 64), 'title' => 'Official', 'source' => 'Nijiiro', 'course' => 4], 'offic');
    $custom = wdb_web_chart(['sha256' => str_repeat('8', 64), 'title' => 'Community', 'source' => 'Nijiiro', 'course' => 3], 'commun');
    wdb_web_play($player, $stock, 800000);
    wdb_web_play($player, $extra, 700000);
    wdb_web_play($player, $custom, 900000);
    wdb_web_play($rival, $custom, 950000);
    $official = $stock->songs()->value('wdb_songs.id');
    $community = $custom->songs()->value('wdb_songs.id');

    // Guests and players who never played them see the stock charts alone.
    foreach ([null, $stranger->user] as $viewer) {
        $request = $viewer === null ? $this : $this->actingAs($viewer);
        $request->get('/waddamburo/songs')->assertInertia(fn (Assert $page) => $page
            ->has('songs.data', 1)->where('songs.data.0.title', 'Official')->where('songs.data.0.play_count', 1));
        $request->get("/waddamburo/songs/{$official}")->assertInertia(fn (Assert $page) => $page
            ->has('difficulties', 1)->where('difficulties.0.level', 4)->has('recentPlays', 1)->where('summary.total_plays', 1));
        $request->get("/waddamburo/songs/{$community}")->assertNotFound();
        $request->get("/waddamburo/users/{$player->user_id}/board")->assertInertia(fn (Assert $page) => $page
            ->has('bestPerformances', 1)->has('recentPlays', 1));
    }

    // Guests can't find the custom chart under its badge either.
    $this->get('/waddamburo/songs?source=Custom')->assertInertia(fn (Assert $page) => $page->has('songs.data', 0));

    // A player who played a custom chart sees it, as Custom, with its other players' scores and their plays of it.
    $this->actingAs($rival->user)->get('/waddamburo/songs')->assertInertia(fn (Assert $page) => $page->has('songs.data', 2));
    $this->actingAs($rival->user)->get('/waddamburo/songs?source=Nijiiro')->assertInertia(fn (Assert $page) => $page
        ->has('songs.data', 1)->where('songs.data.0.title', 'Official'));
    $this->actingAs($rival->user)->get('/waddamburo/songs?source=Custom')->assertInertia(fn (Assert $page) => $page
        ->has('songs.data', 1)->where('songs.data.0.genre.label', 'Custom'));
    $this->actingAs($rival->user)->get("/waddamburo/songs/{$community}")->assertInertia(fn (Assert $page) => $page
        ->where('song.sources', ['Custom'])->where('difficulties.0.sources', ['Custom'])
        ->has('difficulties.0.entries', 2)->where('difficulties.0.entries.1.user_id', $player->user_id));
    // The player sees the custom difficulty beside the official one on the official song.
    $this->actingAs($player->user)->get("/waddamburo/songs/{$official}")->assertInertia(fn (Assert $page) => $page
        ->where('song.sources', ['Nijiiro', 'Custom'])->where('difficulties.1.sources', ['Custom']));
    $this->actingAs($rival->user)->get("/waddamburo/songs/{$official}")->assertInertia(fn (Assert $page) => $page->has('difficulties', 1));
    $this->actingAs($rival->user)->get("/waddamburo/users/{$player->user_id}/board")->assertInertia(fn (Assert $page) => $page
        ->has('bestPerformances', 2)->where('bestPerformances.0.counts_for_leaderboard', false));

    // Admins see every played chart.
    $this->actingAs(User::factory()->create(['role' => 'admin']))->get("/waddamburo/songs/{$official}")
        ->assertInertia(fn (Assert $page) => $page->has('difficulties', 2));

    // Ranking a custom chart publishes it.
    $custom->update(['ranked_at' => now()]);
    $this->get("/waddamburo/songs/{$community}")->assertSuccessful();
});

it('ranks the official charts and the Nijiiro song ids, unranking every other chart but keeping its plays', function (): void {
    $player = wdb_web_player();
    $notes = 'WDBC official notes';
    $official = wdb_web_chart(['sha256' => hash('sha256', $notes), 'title' => 'Official', 'source' => 'Nijiiro', 'course' => 3, 'ranked_at' => now()], 'offic');
    // Ranked by an earlier import from a modded install.
    $custom = wdb_web_chart(['sha256' => str_repeat('a', 64), 'title' => 'Community', 'source' => 'Nijiiro', 'course' => 3, 'ranked_at' => now()], 'commun');
    // A song Nijiiro had before the exported version, a console song, and a TJA under the same id.
    $removed = wdb_web_chart(['sha256' => str_repeat('b', 64), 'title' => 'Removed', 'source' => 'Nijiiro', 'course' => 3], 'removd');
    // A difficulty a modded install added to an exported song.
    $added = wdb_web_chart(['sha256' => str_repeat('e', 64), 'title' => 'Official', 'source' => 'Nijiiro', 'course' => 4, 'ranked_at' => now()], 'offic');
    $console = wdb_web_chart(['sha256' => str_repeat('c', 64), 'title' => 'Console', 'source' => 'Nijiiro', 'course' => 3, 'ranked_at' => now()], 'wiiold');
    $tja = wdb_web_chart(['sha256' => str_repeat('d', 64), 'title' => 'Removed', 'source' => 'Tja', 'course' => 3], 'removd');
    wdb_web_play($player, $official, 800000);
    wdb_web_play($player, $custom, 900000);
    wdb_web_play($player, $removed, 700000);
    $songs = tempnam(sys_get_temp_dir(), 'wdb');
    file_put_contents($songs, "# official songs\nremovd   # 99 Removed (removed 20/4/1～22/1/1)\noffic\n\n");
    $export = tempnam(sys_get_temp_dir(), 'wdb');
    file_put_contents($export, gzencode(json_encode(['sha256' => $official->sha256, 'notes' => base64_encode(gzencode($notes)),
        'title' => 'Official', 'song_key' => 'offic', 'source' => 'Nijiiro', 'course' => 3, 'level' => 9])."\n"));

    $this->artisan('app:import-waddamburo-charts', ['files' => [$export], '--nijiiro-songs' => $songs, '--unrank-custom' => true])
        ->expectsConfirmation('Unrank 3 ranked charts that are not official, including any an admin ranked by hand?', 'yes')
        ->expectsOutput('Ranked 1 charts of 1 Nijiiro song ids the exports lack.')
        ->expectsOutput('Unranked 3 charts that are not stock.')->assertSuccessful();

    expect($official->fresh())->is_stock->toBeTrue()->ranked_at->not->toBeNull()
        ->and($removed->fresh())->is_stock->toBeTrue()->ranked_at->not->toBeNull()
        ->and($custom->fresh())->is_stock->toBeFalse()->ranked_at->toBeNull()
        ->and($console->fresh())->is_stock->toBeFalse()->ranked_at->toBeNull()
        ->and($added->fresh())->is_stock->toBeFalse()->ranked_at->toBeNull()
        ->and($tja->fresh())->is_stock->toBeFalse()->ranked_at->toBeNull()
        ->and($custom->plays()->count())->toBe(1);
    $this->get('/waddamburo/rankings')->assertInertia(fn (Assert $page) => $page->where('entries.0.total_score', 1500000));
});

it('refuses a chart export it cannot read', function (): void {
    $this->artisan('app:import-waddamburo-charts', ['files' => ['/nonexistent/export.jsonl.gz']])
        ->expectsOutput('Cannot read /nonexistent/export.jsonl.gz.')->assertFailed();
});

it('dates a board\'s last play from the charts its viewer may see', function (): void {
    $player = wdb_web_player();
    $rival = wdb_web_player();
    $stock = wdb_web_chart(['sha256' => str_repeat('1', 64), 'title' => 'Official', 'source' => 'Nijiiro', 'course' => 3,
        'is_stock' => true, 'ranked_at' => now()], 'offic');
    $custom = wdb_web_chart(['sha256' => str_repeat('2', 64), 'title' => 'Community', 'source' => 'Nijiiro', 'course' => 3], 'commun');
    $this->travelTo('2026-10-01 12:00:00');
    wdb_web_play($player, $stock, 800000);
    $this->travelTo('2026-10-02 12:00:00');
    wdb_web_play($player, $custom, 900000);
    wdb_web_play($rival, $custom, 900000);

    $this->get("/waddamburo/users/{$player->user_id}/board")->assertInertia(fn (Assert $page) => $page
        ->where('profile.last_played_at', fn ($playedAt) => str_starts_with((string) $playedAt, '2026-10-01')));
    $this->actingAs($rival->user)->get("/waddamburo/users/{$player->user_id}/board")->assertInertia(fn (Assert $page) => $page
        ->where('profile.last_played_at', fn ($playedAt) => str_starts_with((string) $playedAt, '2026-10-02')));
});

it('keeps custom charts ranked when the unranking is not confirmed', function (): void {
    $custom = wdb_web_chart(['sha256' => str_repeat('a', 64), 'title' => 'Community', 'source' => 'Nijiiro', 'course' => 3, 'ranked_at' => now()], 'commun');
    $export = tempnam(sys_get_temp_dir(), 'wdb');
    file_put_contents($export, gzencode(''));

    $this->artisan('app:import-waddamburo-charts', ['files' => [$export], '--unrank-custom' => true])
        ->expectsConfirmation('Unrank 1 ranked charts that are not official, including any an admin ranked by hand?', 'no')
        ->expectsOutput('Unranked 0 charts that are not stock.')->assertSuccessful();
    expect($custom->fresh()->ranked_at)->not->toBeNull();

    $this->artisan('app:import-waddamburo-charts', ['files' => [$export], '--unrank-custom' => true, '--force' => true])
        ->expectsOutput('Unranked 1 charts that are not stock.')->assertSuccessful();
    expect($custom->fresh()->ranked_at)->toBeNull();
});

it('lets admins mark charts and songs official, which ranks them, or custom, which keeps their ranking', function (): void {
    $admin = User::factory()->create(['role' => 'admin']);
    $player = wdb_web_player();
    $oni = wdb_web_chart(['sha256' => str_repeat('a', 64), 'title' => 'Late', 'source' => 'Nijiiro', 'course' => 3], 'late');
    $ura = wdb_web_chart(['sha256' => str_repeat('b', 64), 'title' => 'Late', 'source' => 'Nijiiro', 'course' => 4, 'ranked_at' => now()], 'late');
    wdb_web_play($player, $oni, 800000);
    $song = $oni->songs()->value('wdb_songs.id');

    $this->actingAs($admin)->patch("/waddamburo/admin/waddamburo-charts/{$oni->id}", ['official' => true])->assertRedirect();
    expect($oni->fresh())->is_stock->toBeTrue()->ranked_at->not->toBeNull();
    $this->get('/waddamburo/rankings')->assertInertia(fn (Assert $page) => $page->where('entries.0.total_score', 800000));
    $this->actingAs($admin)->get('/waddamburo/admin/waddamburo-charts?official=some')->assertInertia(fn (Assert $page) => $page
        ->has('songs.data', 1)->where('songs.data.0.official_count', 1)->where('songs.data.0.charts.0.stock', true));

    $this->actingAs($admin)->patch("/waddamburo/admin/waddamburo-songs/{$song}", ['official' => true])->assertRedirect();
    $this->actingAs($admin)->patch("/waddamburo/admin/waddamburo-songs/{$song}", ['official' => false])->assertRedirect();
    expect($oni->fresh())->is_stock->toBeFalse()->ranked_at->not->toBeNull()
        ->and($ura->fresh())->is_stock->toBeFalse()->ranked_at->not->toBeNull();

    $this->actingAs($admin)->patch("/waddamburo/admin/waddamburo-charts/{$oni->id}", [])->assertSessionHasErrors(['ranked', 'official']);
    $this->actingAs($player->user)->patch("/waddamburo/admin/waddamburo-charts/{$oni->id}", ['official' => true])->assertForbidden();
});

it('marks the charts a branched chart replaces legacy, unranked for good, their plays shown under the new chart', function (): void {
    $player = wdb_web_player();
    // What older clients uploaded and an earlier import ranked: the 譜面分岐 chart read as its normal route.
    $oldNotes = 'WDBC normal route';
    $old = wdb_web_chart(['sha256' => hash('sha256', $oldNotes), 'notes' => gzencode($oldNotes), 'title' => '十露盤２０００',
        'source' => 'Stock', 'course' => 3, 'is_stock' => true, 'ranked_at' => now()], 'soroba');
    wdb_web_play($player, $old, 990000);
    $newNotes = 'WDBC with BRCH';
    $line = fn (string $notes, ?string $legacy): string => json_encode(array_filter([
        'sha256' => hash('sha256', $notes), 'legacy_sha256' => $legacy, 'notes' => base64_encode(gzencode($notes)),
        'title' => '十露盤２０００', 'song_key' => 'soroba', 'source' => 'Stock', 'course' => 3, 'level' => 9,
    ]));
    $export = tempnam(sys_get_temp_dir(), 'wdb');
    file_put_contents($export, gzencode($line($newNotes, $old->sha256)."\n"));

    $this->artisan('app:import-waddamburo-charts', ['files' => [$export]])
        ->expectsOutput('Marked 1 charts legacy (older clients\' reading of a branched chart).')->assertSuccessful();

    $new = WdbChart::query()->where('sha256', hash('sha256', $newNotes))->firstOrFail();
    expect($old->fresh())->legacy_at->not->toBeNull()->ranked_at->toBeNull()
        ->and($new->ranked_at)->not->toBeNull();
    // An older export listing the legacy chart again does not rank it back.
    file_put_contents($export, gzencode($line($oldNotes, null)."\n"));
    $this->artisan('app:import-waddamburo-charts', ['files' => [$export]])->assertSuccessful();
    expect($old->fresh()->ranked_at)->toBeNull();

    // The new chart's board, its legacy one folded under it; out of the rankings.
    auth()->logout();
    $this->get("/waddamburo/songs/{$new->songs()->value('wdb_songs.id')}")->assertInertia(fn (Assert $page) => $page
        ->has('difficulties', 1)->where('difficulties.0.id', $new->id)->has('difficulties.0.entries', 0)
        ->where('difficulties.0.legacy.0.id', $old->id)->where('difficulties.0.legacy.0.entries.0.score', 990000));
    $this->get('/waddamburo/rankings')->assertInertia(fn (Assert $page) => $page->has('entries', 0));
});
