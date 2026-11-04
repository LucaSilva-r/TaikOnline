<?php

use App\Models\Player;
use App\Models\PlayerCosmetic;
use App\Models\User;
use App\Models\WdbDanCourse;
use App\Models\WdbDanPlay;
use Illuminate\Support\Facades\DB;
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
        'score' => 1, 'great' => 1, 'good' => 0, 'miss' => 0, 'max_combo' => 1, 'rolls' => 0,
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
