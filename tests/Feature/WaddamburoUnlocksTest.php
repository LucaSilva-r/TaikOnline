<?php

use App\Models\GameCard;
use App\Models\Player;
use App\Models\PlayerCosmetic;
use App\Models\PlayerShopItem;
use App\Models\Song;
use App\Models\User;
use App\Services\WaddamburoAiBattles;
use App\Services\WaddamburoUnlocks;
use Inertia\Testing\AssertableInertia as Assert;

it('locks the reward shop\'s goods in "Only what I earned" until bought here or on a cabinet', function (): void {
    $this->travelTo('2026-11-19 12:00:00'); // the fall shop: heads 53/51, bodies 132/101, songs 855, 852, 851, 850
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);
    GameCard::query()->create(['access_code' => '12345678901234567890', 'baid' => $player->baid]);
    Song::query()->create(['version' => 'green', 'song_no' => 855, 'music_id' => 'shopsong', 'unique_id' => 855,
        'title' => 'Shop song', 'genre' => 'namco_original', 'partsset' => 'A3']);
    $token = $user->createToken('unlocks', ['wdb'])->plainTextToken;
    $me = fn () => $this->withToken($token)->getJson('/api/wdb/me')->assertOk();

    // Only what I earned is the default; Unlock everything locks nothing.
    $me()->assertJsonPath('earned_only', true);
    $this->actingAs($user)->patch('/waddamburo/settings/unlock-mode', ['earned_only' => false])->assertRedirect();
    $me()->assertJsonPath('earned_only', false)->assertJsonPath('locked.head', [])->assertJsonPath('locked.songs', []);

    $this->actingAs($user)->patch('/waddamburo/settings/unlock-mode', ['earned_only' => true])->assertRedirect();
    $me()->assertJsonPath('earned_only', true)->assertJsonPath('locked.songs', ['shopsong'])
        ->assertJsonPath('locked.head', fn (array $heads) => in_array(53, $heads, true) && in_array(51, $heads, true));

    // A real Green cabinet's unlock (head 51, costume slot 2) and a Waddamburo purchase (head 53) both count.
    PlayerCosmetic::create(['baid' => $player->baid, 'game_version' => 'green', 'unlocked_costumes' => ['2' => [51]]]);
    PlayerShopItem::query()->create(['baid' => $player->baid, 'game_version' => 'waddamburo', 'season_id' => 3,
        'item_type' => 5, 'item_id' => 53, 'item_no' => 1, 'item_price' => 500]);
    $me()->assertJsonPath('locked.head', fn (array $heads) => ! in_array(53, $heads, true) && ! in_array(51, $heads, true))
        ->assertJsonPath('locked.body', fn (array $bodies) => in_array(132, $bodies, true));

    // The website greys locked costumes and refuses them.
    $this->actingAs($user)->get('/waddamburo/settings/costumes')
        ->assertInertia(fn (Assert $page) => $page->where('earnedOnly', true)->where('lockedCostumes.body', fn ($bodies) => collect($bodies)->contains(132)));
    $set = fn (int $body): array => ['active_preset' => 0, 'presets' => array_fill(0, 3,
        ['costume_1' => 0, 'costume_2' => 0, 'costume_3' => $body, 'costume_5' => 0])];
    $this->actingAs($user)->patch('/waddamburo/settings/costumes', $set(132))->assertSessionHasErrors('presets');
    $this->actingAs($user)->patch('/waddamburo/settings/costumes', $set(0))->assertSessionHasNoErrors(); // body 1 is a pool item now
});

it('unlocks Green\'s AI battle rewards at their win counts', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);
    PlayerCosmetic::create(['baid' => $player->baid, 'game_version' => 'waddamburo', 'wdb_earned_only' => true]);
    foreach ([[819, 'csmclr'], [833, 'gfish']] as [$unique, $music]) {
        Song::query()->create(['version' => 'green', 'song_no' => $unique, 'music_id' => $music, 'unique_id' => $unique,
            'title' => $music, 'genre' => 'namco_original', 'partsset' => 'A3']);
    }
    $this->mock(WaddamburoAiBattles::class, fn ($mock) => $mock->shouldReceive('progress')
        ->andReturn(['points' => 0, 'wins' => 30, 'rank' => 0, 'points_to_next' => 1]));

    // 30 wins: カラフルボイス (3) is had, Goldfish City (55) and マンガ目 (85) are not.
    $locked = app(WaddamburoUnlocks::class)->locked($player);
    expect($locked['songs'])->toContain('gfish')->not->toContain('csmclr')
        ->and($locked['head'])->toContain(119);
});

it('locks what Green\'s condition titles give until the title is earned', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);
    PlayerCosmetic::create(['baid' => $player->baid, 'game_version' => 'waddamburo', 'wdb_earned_only' => true]);
    $unlocks = fn () => app(WaddamburoUnlocks::class)->locked($player->refresh());
    $this->mock(WaddamburoAiBattles::class, fn ($mock) => $mock->shouldReceive('progress')
        ->andReturn(['points' => 0, 'wins' => 0, 'rank' => 0, 'points_to_next' => 1]));

    // Title 152 (clear a credit of katakana-titled songs) gives kigurumi 56 チリチリロープ.
    expect($unlocks()['kigurumi'])->toContain(56);
    PlayerCosmetic::create(['baid' => $player->baid, 'game_version' => 'green', 'unlocked_titles' => [152]]);
    expect($unlocks()['kigurumi'])->not->toContain(56);
})->skip(fn () => ! file_exists(config('taiko_green.data_path').'/green/green_reward_table.json'), 'needs the Green reward table data file');

it('lists rewards earned in Waddamburo until the game has shown them', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);
    Song::query()->create(['version' => 'green', 'song_no' => 819, 'music_id' => 'csmclr', 'unique_id' => 819,
        'title' => 'csmclr', 'genre' => 'namco_original', 'partsset' => 'A3']);
    $this->mock(WaddamburoAiBattles::class, fn ($mock) => $mock->shouldReceive('progress')
        ->andReturn(['points' => 0, 'wins' => 90, 'rank' => 0, 'points_to_next' => 1]));
    $token = $user->createToken('rewards', ['wdb'])->plainTextToken;
    $pending = fn () => $this->withToken($token)->getJson('/api/wdb/me')->assertOk()->json('pending_rewards');

    // 90 wins: カラフルボイス (3), 疾風怒濤 (25), Goldfish City (55) and head マンガ目 (85); the head was had on a cabinet.
    PlayerCosmetic::create(['baid' => $player->baid, 'game_version' => 'green', 'unlocked_costumes' => ['2' => [119]]]);
    expect($pending())->toHaveCount(3)->and($pending()[0])->toBe(['type' => 1, 'id' => 819, 'music_id' => 'csmclr']);

    $this->withToken($token)->postJson('/api/wdb/rewards/seen', ['items' => [[1, 819], [1, 834]]])->assertOk();
    expect($pending())->toBe([['type' => 1, 'id' => 833, 'music_id' => null]]);
});

it('does not count wearing an official title as earning it, and earned-only wears earned titles only', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);
    GameCard::query()->create(['access_code' => '12345678901234567891', 'baid' => $player->baid]);
    $wear = fn (int $id) => $this->actingAs($user)->patch('/waddamburo/settings/donchan-official-title', ['title_id' => $id]);

    // Unlock everything: any title can be worn, and it is not earned (253 would unlock the IA kigurumi).
    $this->actingAs($user)->patch('/waddamburo/settings/unlock-mode', ['earned_only' => false]);
    $wear(253)->assertRedirect();
    expect(PlayerCosmetic::query()->where('baid', $player->baid)->where('game_version', 'waddamburo')->value('unlocked_titles') ?? [])
        ->not->toContain(253);

    // Only what I earned: an unearned title is refused, an earned one worn.
    $this->actingAs($user)->patch('/waddamburo/settings/unlock-mode', ['earned_only' => true]);
    $wear(106)->assertStatus(422);
    PlayerCosmetic::query()->where('baid', $player->baid)->where('game_version', 'waddamburo')->update(['unlocked_titles' => json_encode([106])]);
    $wear(106)->assertRedirect();
    $this->actingAs($user)->get('/waddamburo/settings/costumes')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('officialTitles', fn ($titles) => collect($titles)->pluck('id')->all() === [106]));
});
