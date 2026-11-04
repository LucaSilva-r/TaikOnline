<?php

use App\Models\Player;
use App\Models\PlayerCosmetic;
use App\Models\User;
use App\Models\WdbChart;
use App\Models\WdbDanCourse;
use App\Models\WdbDanPlay;
use App\Models\WdbPlay;
use App\Services\WaddamburoDanRank;
use App\Services\WaddamburoRankAggregateService;
use App\Services\WaddamburoSongs;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function rank_player(): Player
{
    return Player::query()->create(['user_id' => User::factory()->create()->id, 'mydon_name' => 'Player'])->fresh('user');
}

function rank_course(string $name = '七段', string $series = 'red', int $level = 18): WdbDanCourse
{
    return WdbDanCourse::query()->create(['series' => $series, 'level' => $level, 'name' => $name,
        'content_hash' => hash('sha256', $series.$name), 'requirements' => []]);
}

function rank_clear(Player $player, WdbDanCourse $course, array $overrides = []): WdbDanPlay
{
    return WdbDanPlay::query()->create([
        'baid' => $player->baid, 'content_hash' => $course->content_hash, 'result_level' => 2,
        'score' => 1, 'great' => 1, 'good' => 1, 'miss' => 0, 'max_combo' => 1, 'rolls' => 0,
        'gauge_percent' => 100, 'songs_played' => 3, 'scoring_version' => 4, 'engine_version' => 'test',
        'played_at' => now(), 'rescored_at' => now(), ...$overrides,
    ]);
}

it('saves the chosen series to the Waddamburo loadout and serves its verified gold status', function (): void {
    $player = rank_player();
    $course = rank_course();
    rank_clear($player, $course);
    PlayerCosmetic::create(['baid' => $player->baid, 'game_version' => 'green', 'title' => 'Green only']);

    $this->actingAs($player->user)->patch('/waddamburo/settings/dan-rank', [
        'series' => 'red', 'baid' => 999, 'dan_rank' => 25, 'dan_gold' => false,
    ])->assertRedirect(route('board.show', ['taikoVersion' => 'waddamburo', 'user' => $player->user_id]));

    $this->assertDatabaseHas('player_cosmetics', ['baid' => $player->baid, 'game_version' => 'waddamburo',
        'wdb_dan_series' => 'red']);
    $this->assertDatabaseHas('player_cosmetics', ['baid' => $player->baid, 'game_version' => 'green', 'wdb_dan_series' => null]);
    $this->withToken($player->user->createToken('rank-test', ['wdb'])->plainTextToken)->getJson('/api/wdb/me')
        ->assertOk()->assertJson(['dan_rank' => 18, 'dan_gold' => true]);
    $this->get(route('board.show', ['taikoVersion' => 'waddamburo', 'user' => $player->user_id]))
        ->assertInertia(fn (Assert $page) => $page->where('canChooseDanRank', true)
            ->where('displayDanRank.series', 'red')->where('displayDanRank.rank', 18));
});

it('clears the chosen series when the player hides their rank', function (): void {
    $player = rank_player();
    $course = rank_course();
    PlayerCosmetic::create(['baid' => $player->baid, 'game_version' => 'waddamburo',
        'wdb_dan_series' => 'red']);

    $this->actingAs($player->user)->patch('/waddamburo/settings/dan-rank', ['series' => null])->assertRedirect();

    $this->assertDatabaseHas('player_cosmetics', ['baid' => $player->baid, 'game_version' => 'waddamburo',
        'wdb_dan_series' => null]);
    $this->getJson('/api/wdb/me')->assertOk()->assertJson(['dan_rank' => 0, 'dan_gold' => false]);
});

it('rejects series without a verified visible rank clear belonging to the player', function (string $reason): void {
    $player = rank_player();
    $course = rank_course($reason === 'challenge' ? 'Synthetic challenge' : '七段');
    $overrides = match ($reason) {
        'failed' => ['result_level' => 0],
        'unverified' => ['rescored_at' => null],
        'hidden' => ['hidden_at' => now()],
        default => [],
    };
    rank_clear($reason === 'another player' ? rank_player() : $player, $course, $overrides);
    if ($reason === 'disabled series') {
        DB::table('wdb_dan_disabled_series')->insert(['series' => 'red']);
    }

    $this->actingAs($player->user)->patch('/waddamburo/settings/dan-rank', ['series' => 'red'])
        ->assertSessionHasErrors(['series' => 'Choose a series with a verified cleared rank.']);

    $this->assertDatabaseMissing('player_cosmetics', ['baid' => $player->baid, 'game_version' => 'waddamburo']);
})->with(['failed', 'unverified', 'hidden', 'another player', 'challenge', 'disabled series']);

it('hides an equipped rank whose course changed or whose verified clear was withdrawn', function (string $reason): void {
    $player = rank_player();
    $course = rank_course();
    $play = rank_clear($player, $course);
    PlayerCosmetic::create(['baid' => $player->baid, 'game_version' => 'waddamburo',
        'wdb_dan_series' => 'red']);
    match ($reason) {
        'changed course' => $course->update(['content_hash' => str_repeat('a', 64)]),
        'hidden clear' => $play->update(['hidden_at' => now()]),
        'rescored failure' => $play->update(['result_level' => 0]),
        'disabled series' => DB::table('wdb_dan_disabled_series')->insert(['series' => 'red']),
    };

    $this->withToken($player->user->createToken('rank-test', ['wdb'])->plainTextToken)->getJson('/api/wdb/me')
        ->assertOk()->assertJson(['dan_rank' => 0, 'dan_gold' => false]);
})->with(['changed course', 'hidden clear', 'rescored failure', 'disabled series']);

it('uses the remaining normal clear when a gold clear is hidden', function (): void {
    $player = rank_player();
    $course = rank_course();
    rank_clear($player, $course, ['result_level' => 1]);
    rank_clear($player, $course, ['hidden_at' => now()]);
    PlayerCosmetic::create(['baid' => $player->baid, 'game_version' => 'waddamburo',
        'wdb_dan_series' => 'red']);

    $this->withToken($player->user->createToken('rank-test', ['wdb'])->plainTextToken)->getJson('/api/wdb/me')
        ->assertOk()->assertJson(['dan_rank' => 18, 'dan_gold' => false]);
});

it('requires a logged-in player and the Waddamburo scope', function (): void {
    $this->patch('/waddamburo/settings/dan-rank', ['series' => null])->assertRedirect();
    $this->actingAs(User::factory()->create())->patch('/waddamburo/settings/dan-rank', ['series' => null])->assertNotFound();
    $player = rank_player();
    $this->actingAs($player->user)->patch('/green/settings/dan-rank', ['series' => null])->assertNotFound();
    $this->assertDatabaseMissing('player_cosmetics', ['baid' => $player->baid]);
});

it('rejects malformed series selections without changing the preference', function (array $payload): void {
    $player = rank_player();
    $this->actingAs($player->user)->patch('/waddamburo/settings/dan-rank', $payload)->assertSessionHasErrors('series');
    $this->assertDatabaseMissing('player_cosmetics', ['baid' => $player->baid]);
})->with(['missing' => [[]], 'not a series' => [['series' => 42]], 'unknown series' => [['series' => 'unknown']]]);

it('hides the rank picker on somebody else\'s board', function (): void {
    $owner = rank_player();
    $viewer = User::factory()->create();

    $this->actingAs($viewer)->get(route('board.show', ['taikoVersion' => 'waddamburo', 'user' => $owner->user_id]))
        ->assertInertia(fn (Assert $page) => $page->where('canChooseDanRank', false));
});

it('maps older rank names onto the available nameplate art', function (string $name, int $rank): void {
    expect((new WdbDanCourse(['name' => $name]))->nameplateRank())->toBe($rank);
})->with([['初級', 1], ['１級', 11], ['初段', 12], ['七段', 18], ['達人', 25], ['副将', 15], ['Synthetic challenge', 0]]);

it('automatically advances to the highest cleared rank, using that rank\'s own medal', function (): void {
    $player = rank_player();
    $lower = rank_course('六段', 'red', 17);
    rank_clear($player, $lower);
    PlayerCosmetic::create(['baid' => $player->baid, 'game_version' => 'waddamburo', 'wdb_dan_series' => 'red']);
    $token = $player->user->createToken('rank-test', ['wdb'])->plainTextToken;
    $this->withToken($token)->getJson('/api/wdb/me')->assertJson(['dan_rank' => 17, 'dan_gold' => true]);
    $higher = rank_course();
    rank_clear($player, $higher, ['result_level' => 1]);
    $challenge = rank_course('Synthetic challenge', 'red', 101);
    rank_clear($player, $challenge);
    $other = rank_course('達人', 'green', 25);
    rank_clear($player, $other);

    $this->getJson('/api/wdb/me')->assertJson(['dan_rank' => 18, 'dan_gold' => false]);

    $this->assertDatabaseHas('player_cosmetics', ['baid' => $player->baid, 'game_version' => 'waddamburo', 'wdb_dan_series' => 'red']);
});

it('falls back to the next highest verified rank if the top course changes or its clear is hidden', function (string $reason): void {
    $player = rank_player();
    $lower = rank_course('六段', 'red', 17);
    rank_clear($player, $lower);
    $higher = rank_course();
    $play = rank_clear($player, $higher, ['result_level' => 1]);
    PlayerCosmetic::create(['baid' => $player->baid, 'game_version' => 'waddamburo', 'wdb_dan_series' => 'red']);
    match ($reason) {
        'changed course' => $higher->update(['content_hash' => str_repeat('a', 64)]),
        'hidden clear' => $play->update(['hidden_at' => now()]),
    };

    $this->withToken($player->user->createToken('rank-test', ['wdb'])->plainTextToken)->getJson('/api/wdb/me')
        ->assertOk()->assertJson(['dan_rank' => 17, 'dan_gold' => true]);
})->with(['changed course', 'hidden clear']);

it('batches each user\'s selected dojo and highest rank with that course\'s own medal', function (): void {
    $red = rank_player();
    $green = rank_player();
    $notRequested = rank_player();
    $lower = rank_course('六段', 'red', 17);
    $higher = rank_course();
    $otherDojo = rank_course('二段', 'green', 13);
    rank_clear($red, $lower);
    rank_clear($red, $higher, ['result_level' => 1]);
    rank_clear($green, $otherDojo);
    rank_clear($green, $higher);
    rank_clear($notRequested, $higher);
    foreach ([$red, $green, $notRequested] as $player) {
        PlayerCosmetic::create(['baid' => $player->baid, 'game_version' => 'waddamburo',
            'wdb_dan_series' => $player->is($green) ? 'green' : 'red']);
    }

    $ranks = app(WaddamburoDanRank::class)->forUsers([$red->user_id, $green->user_id, $red->user_id]);

    expect($ranks)->toBe([
        $red->user_id => ['series' => 'red', 'label' => '7 Dan', 'name' => '七段', 'rank' => 18, 'gold' => false, 'perfect' => false, 'dojo' => 'Red'],
        $green->user_id => ['series' => 'green', 'label' => '2 Dan', 'name' => '二段', 'rank' => 13, 'gold' => true, 'perfect' => false, 'dojo' => 'Green'],
    ]);
});

it('omits a dashboard rank without a selected valid verified clear', function (string $reason): void {
    $player = rank_player();
    $course = rank_course();
    $play = rank_clear($player, $course);
    $cosmetic = PlayerCosmetic::create(['baid' => $player->baid, 'game_version' => 'waddamburo', 'wdb_dan_series' => 'red']);
    match ($reason) {
        'unverified' => $play->update(['rescored_at' => null]),
        'hidden' => $play->update(['hidden_at' => now()]),
        'failed' => $play->update(['result_level' => 0]),
        'stale' => $course->update(['content_hash' => str_repeat('b', 64)]),
        'disabled' => DB::table('wdb_dan_disabled_series')->insert(['series' => 'red']),
        'unselected' => $cosmetic->update(['wdb_dan_series' => null]),
        'unknown dojo' => $cosmetic->update(['wdb_dan_series' => 'unknown']),
        'other version' => $cosmetic->update(['game_version' => 'green']),
        'challenge only' => $course->update(['name' => 'Synthetic challenge']),
    };

    expect(app(WaddamburoDanRank::class)->forUsers([$player->user_id]))->toBe([]);
})->with(['unverified', 'hidden', 'failed', 'stale', 'disabled', 'unselected', 'unknown dojo', 'other version', 'challenge only']);

it('serves the equipped rank beside player identities across the dashboard', function (): void {
    $player = rank_player();
    $course = rank_course();
    $danPlay = rank_clear($player, $course);
    PlayerCosmetic::create(['baid' => $player->baid, 'game_version' => 'waddamburo', 'wdb_dan_series' => 'red']);
    $chart = WdbChart::create(['sha256' => str_repeat('c', 64), 'title' => 'Rank tag fixture',
        'course' => 3, 'ranked_at' => now()]);
    WaddamburoSongs::attach([['wdb_chart_id' => $chart->id, 'title' => $chart->title, 'course' => 3]]);
    $play = WdbPlay::create(['id' => (string) Str::uuid(),
        'baid' => $player->baid, 'wdb_chart_id' => $chart->id, 'mode' => 'normal', 'course' => 3,
        'score' => 100, 'great' => 1, 'good' => 0, 'miss' => 0, 'max_combo' => 1, 'rolls' => 0,
        'gauge' => 50, 'cleared' => true, 'scoring_version' => 4, 'engine_version' => 'test',
        'played_at' => now(), 'rescored_at' => now(), 'replay' => 'synthetic']);
    app(WaddamburoRankAggregateService::class)->recomputeChart($chart->id);
    $songId = $chart->songs()->value('wdb_songs.id');

    foreach (["/waddamburo/users/{$player->user_id}/board", "/green/users/{$player->user_id}/board",
        '/waddamburo/rankings', "/waddamburo/songs/{$songId}", "/waddamburo/plays/{$play->id}",
        "/waddamburo/dan-plays/{$danPlay->id}"] as $url) {
        $this->get($url)->assertInertia(fn (Assert $page) => $page
            ->where("danRanks.{$player->user_id}.label", '7 Dan')
            ->where("danRanks.{$player->user_id}.name", '七段')
            ->where("danRanks.{$player->user_id}.dojo", 'Red')
            ->where("danRanks.{$player->user_id}.gold", true));
    }
});

it('refreshes the account menu rank when the player hides their rank', function (): void {
    $player = rank_player();
    rank_clear($player, rank_course());
    PlayerCosmetic::create(['baid' => $player->baid, 'game_version' => 'waddamburo', 'wdb_dan_series' => 'red']);
    $url = "/waddamburo/users/{$player->user_id}/board";
    $this->actingAs($player->user)->get($url)->assertInertia(fn (Assert $page) => $page->where('auth.danRank.label', '7 Dan'));

    $this->patch('/waddamburo/settings/dan-rank', ['series' => null])->assertRedirect();

    $this->get($url)->assertInertia(fn (Assert $page) => $page->where('danRanks', [])->where('auth.danRank', null));
});

it('recognizes an existing verified all-Great clear as perfect while keeping its game medal', function (int $medal): void {
    $player = rank_player();
    $course = rank_course();
    $play = rank_clear($player, $course, ['result_level' => $medal, 'great' => 300, 'good' => 0, 'miss' => 0]);
    PlayerCosmetic::create(['baid' => $player->baid, 'game_version' => 'waddamburo', 'wdb_dan_series' => 'red']);

    $this->get("/waddamburo/users/{$player->user_id}/board")->assertInertia(fn (Assert $page) => $page
        ->where('displayDanRank.perfect', true)
        ->where("danRanks.{$player->user_id}.perfect", true)
        ->where('danProgress.0.ranks_perfect', 1)
        ->where('danProgress.0.top_perfect', true)
        ->where('danProgress.0.courses.0.perfect', true)
        ->where('danProgress.0.courses.0.run', $play->id));
    $this->withToken($player->user->createToken('perfect-test', ['wdb'])->plainTextToken)->getJson('/api/wdb/me')
        ->assertJson(['dan_rank' => 18, 'dan_gold' => $medal === WdbDanPlay::GOLD])
        ->assertJsonMissingPath('dan_perfect');
})->with(['normal clear' => [1], 'gold clear' => [2]]);

it('does not award a perfect badge for an ineligible run', function (string $reason): void {
    $player = rank_player();
    $course = rank_course();
    rank_clear($player, $course);
    $invalid = ['great' => 300, 'good' => 0, 'miss' => 0];
    $invalid = [...$invalid, ...match ($reason) {
        'Ok judgement' => ['good' => 1],
        'Bad judgement' => ['miss' => 1],
        'no notes' => ['great' => 0],
        'no songs' => ['songs_played' => 0],
        'failed course' => ['result_level' => 0],
        'unverified' => ['rescored_at' => null],
        'hidden' => ['hidden_at' => now()],
        'another player' => ['baid' => rank_player()->baid],
        'outdated course' => ['content_hash' => str_repeat('d', 64)],
    }];
    rank_clear($player, $course, $invalid);
    PlayerCosmetic::create(['baid' => $player->baid, 'game_version' => 'waddamburo', 'wdb_dan_series' => 'red']);

    $this->get("/waddamburo/users/{$player->user_id}/board")->assertInertia(fn (Assert $page) => $page
        ->where('displayDanRank.perfect', false)
        ->where("danRanks.{$player->user_id}.perfect", false)
        ->where('danProgress.0.ranks_perfect', 0)
        ->where('danProgress.0.courses.0.perfect', false));
})->with(['Ok judgement', 'Bad judgement', 'no notes', 'no songs', 'failed course', 'unverified', 'hidden', 'another player', 'outdated course']);

it('keeps a lower perfect rank and a perfect challenge from coloring the highest equipped rank', function (): void {
    $player = rank_player();
    $lower = rank_course('六段', 'red', 17);
    rank_clear($player, $lower, ['good' => 0]);
    $higher = rank_course();
    rank_clear($player, $higher);
    $challenge = rank_course('Synthetic challenge', 'red', 101);
    rank_clear($player, $challenge, ['good' => 0]);
    PlayerCosmetic::create(['baid' => $player->baid, 'game_version' => 'waddamburo', 'wdb_dan_series' => 'red']);

    $this->get("/waddamburo/users/{$player->user_id}/board")->assertInertia(fn (Assert $page) => $page
        ->where('displayDanRank.rank', 18)->where('displayDanRank.perfect', false)
        ->where('danProgress.0.top_perfect', false)->where('danProgress.0.ranks_perfect', 1)
        ->where('danProgress.0.courses.0.perfect', true)
        ->where('danProgress.0.courses.2.perfect', true));
});

it('links a rainbow course to its perfect run and keeps gold from a separate run', function (): void {
    $player = rank_player();
    $course = rank_course();
    $perfect = rank_clear($player, $course, ['result_level' => 1, 'good' => 0, 'score' => 100]);
    $gold = rank_clear($player, $course, ['result_level' => 2, 'score' => 1000]);
    PlayerCosmetic::create(['baid' => $player->baid, 'game_version' => 'waddamburo', 'wdb_dan_series' => 'red']);
    $url = "/waddamburo/users/{$player->user_id}/board";

    $this->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('displayDanRank.perfect', true)->where('displayDanRank.gold', true)
        ->where('danProgress.0.courses.0.best', 2)->where('danProgress.0.courses.0.run', $perfect->id));

    $perfect->update(['hidden_at' => now()]);

    $this->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('displayDanRank.perfect', false)->where('displayDanRank.gold', true)
        ->where('danProgress.0.courses.0.run', $gold->id));
});
