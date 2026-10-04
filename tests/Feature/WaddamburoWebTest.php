<?php

use App\Models\Player;
use App\Models\User;
use App\Models\WdbChart;
use App\Models\WdbPlay;
use App\Services\WaddamburoRankAggregateService;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function wdb_web_player(): Player
{
    $user = User::factory()->create();

    return Player::query()->create(['user_id' => $user->id, 'mydon_name' => 'どん'])->fresh('user');
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
    $oni = WdbChart::query()->create(['sha256' => str_repeat('a', 64), 'title' => 'Song', 'source' => 'TJA', 'course' => 3]);
    $hard = WdbChart::query()->create(['sha256' => str_repeat('b', 64), 'title' => 'Song', 'source' => 'TJA', 'course' => 2]);
    wdb_web_play($player, $oni, 800000);
    wdb_web_play($player, $oni, 900000, good: 0);
    wdb_web_play($player, $hard, 700000, cleared: false);

    $this->get('/waddamburo/songs')->assertSuccessful()->assertInertia(fn (Assert $page) => $page
        ->component('Songs')->where('gameVersion.value', 'waddamburo')->has('songs.data', 1)
        ->where('songs.data.0.play_count', 3));
    $this->get("/waddamburo/songs/{$oni->id}")->assertSuccessful()->assertInertia(fn (Assert $page) => $page
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
    $chart = WdbChart::query()->create(['sha256' => str_repeat('c', 64), 'title' => 'Ranked', 'course' => 3]);
    wdb_web_play($player, $chart, 850000);

    $this->actingAs($admin)->get('/waddamburo/admin/waddamburo-charts')->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('admin/WaddamburoCharts')->where('charts.data.0.ranked', false));
    $this->actingAs($admin)->patch("/waddamburo/admin/waddamburo-charts/{$chart->id}", ['ranked' => true])->assertRedirect();

    expect($chart->fresh()->ranked_at)->not->toBeNull();
    $this->get('/waddamburo/rankings')->assertInertia(fn (Assert $page) => $page->where('entries.0.total_score', 850000));

    $this->actingAs($player->user)->patch("/waddamburo/admin/waddamburo-charts/{$chart->id}", ['ranked' => false])->assertForbidden();
});

it('lists Waddamburo charts filtered by source, sorted, and linked to their song page', function (): void {
    $admin = User::factory()->create(['role' => 'admin']);
    $hard = WdbChart::query()->create(['sha256' => str_repeat('d', 64), 'title' => 'Song', 'source' => 'Stock', 'course' => 2, 'level' => 5]);
    $oni = WdbChart::query()->create(['sha256' => str_repeat('e', 64), 'title' => 'Song', 'source' => 'Stock', 'course' => 3, 'level' => 8]);
    WdbChart::query()->create(['sha256' => str_repeat('f', 64), 'title' => 'Custom', 'source' => 'Tja', 'course' => 3]);
    WdbChart::query()->create(['sha256' => str_repeat('0', 64)]);

    $this->actingAs($admin)->get('/waddamburo/admin/waddamburo-charts?source=Stock&sort=course&direction=desc')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('admin/WaddamburoCharts')
            ->where('sources', ['Stock', 'Tja'])
            ->has('charts.data', 2)
            ->where('charts.data.0.id', $oni->id)
            ->where('charts.data.0.song_id', $hard->id)
            ->where('charts.data.1.song_id', $hard->id));
    $this->actingAs($admin)->get('/waddamburo/admin/waddamburo-charts?sort=title&direction=asc')
        ->assertInertia(fn (Assert $page) => $page->where('charts.data.3.song_id', null)->where('charts.data.3.has_notes', false));
});

it('imports and ranks exported stock and Nijiiro charts, skipping lines whose notes do not match', function (): void {
    $player = wdb_web_player();
    $notes = 'WDBC canonical notes';
    $sha256 = hash('sha256', $notes);
    $played = WdbChart::query()->create(['sha256' => $sha256, 'course' => 3]);
    wdb_web_play($player, $played, 900000);
    $line = fn (string $sha, string $title): string => json_encode([
        'sha256' => $sha, 'notes' => base64_encode(gzencode($notes)), 'title' => $title,
        'subtitle' => null, 'source' => 'Stock', 'course' => 3, 'level' => 9,
    ]);
    $export = tempnam(sys_get_temp_dir(), 'wdb');
    file_put_contents($export, gzencode($line($sha256, 'Stock song')."\n".$line(str_repeat('9', 64), 'Forged')."\n"));

    $this->artisan('app:import-waddamburo-charts', ['file' => $export])
        ->expectsOutput('Imported and ranked 1 charts (1 invalid lines skipped).')->assertSuccessful();

    expect(WdbChart::query()->count())->toBe(1)
        ->and($played->fresh())->title->toBe('Stock song')->level->toBe(9)->ranked_at->not->toBeNull();
    $this->get('/waddamburo/rankings')->assertInertia(fn (Assert $page) => $page->where('entries.0.total_score', 900000));
});

it('edits the Green Don-chan from the Waddamburo scope', function (): void {
    $player = wdb_web_player();

    $this->actingAs($player->user)->get('/waddamburo/settings/costumes')->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('settings/DonChan')->where('supported', true)
            ->where('taikoVersion.current.supports.costumeSlots', true));
});
