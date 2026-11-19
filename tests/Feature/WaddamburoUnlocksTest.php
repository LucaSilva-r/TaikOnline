<?php

use App\Models\GameCard;
use App\Models\Player;
use App\Models\PlayerCosmetic;
use App\Models\Song;
use App\Models\User;
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

    // Unlock everything (the default): nothing locked.
    $me()->assertJsonPath('earned_only', false)->assertJsonPath('locked.head', [])->assertJsonPath('locked.songs', []);

    $this->actingAs($user)->patch('/waddamburo/settings/unlock-mode', ['earned_only' => true])->assertRedirect();
    $me()->assertJsonPath('earned_only', true)->assertJsonPath('locked.songs', ['shopsong'])
        ->assertJsonPath('locked.head', fn (array $heads) => in_array(53, $heads, true) && in_array(51, $heads, true));

    // A real Green cabinet's unlock (head 51, costume slot 2) and a Waddamburo purchase (head 53) both count.
    PlayerCosmetic::create(['baid' => $player->baid, 'game_version' => 'green', 'unlocked_costumes' => ['2' => [51]]]);
    \App\Models\PlayerShopItem::query()->create(['baid' => $player->baid, 'game_version' => 'waddamburo', 'season_id' => 3,
        'item_type' => 5, 'item_id' => 53, 'item_no' => 1, 'item_price' => 500]);
    $me()->assertJsonPath('locked.head', fn (array $heads) => ! in_array(53, $heads, true) && ! in_array(51, $heads, true))
        ->assertJsonPath('locked.body', fn (array $bodies) => in_array(132, $bodies, true));

    // The website greys locked costumes and refuses them.
    $this->actingAs($user)->get('/waddamburo/settings/costumes')
        ->assertInertia(fn (Assert $page) => $page->where('earnedOnly', true)->where('lockedCostumes.body', fn ($bodies) => collect($bodies)->contains(132)));
    $set = fn (int $body): array => ['active_preset' => 0, 'presets' => array_fill(0, 3,
        ['costume_1' => 0, 'costume_2' => 0, 'costume_3' => $body, 'costume_5' => 0])];
    $this->actingAs($user)->patch('/waddamburo/settings/costumes', $set(132))->assertSessionHasErrors('presets');
    $this->actingAs($user)->patch('/waddamburo/settings/costumes', $set(1))->assertSessionHasNoErrors();
});

it('unlocks Green\'s AI battle rewards at their win counts', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);
    PlayerCosmetic::create(['baid' => $player->baid, 'game_version' => 'waddamburo', 'wdb_earned_only' => true]);
    foreach ([[819, 'csmclr'], [833, 'gfish']] as [$unique, $music]) {
        Song::query()->create(['version' => 'green', 'song_no' => $unique, 'music_id' => $music, 'unique_id' => $unique,
            'title' => $music, 'genre' => 'namco_original', 'partsset' => 'A3']);
    }
    $this->mock(\App\Services\WaddamburoAiBattles::class, fn ($mock) => $mock->shouldReceive('progress')
        ->andReturn(['points' => 0, 'wins' => 30, 'rank' => 0, 'points_to_next' => 1]));

    // 30 wins: カラフルボイス (3) is had, Goldfish City (55) and マンガ目 (85) are not.
    $locked = app(\App\Services\WaddamburoUnlocks::class)->locked($player);
    expect($locked['songs'])->toContain('gfish')->not->toContain('csmclr')
        ->and($locked['head'])->toContain(119);
});

it('locks what Green\'s condition titles give until the title is earned', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);
    PlayerCosmetic::create(['baid' => $player->baid, 'game_version' => 'waddamburo', 'wdb_earned_only' => true]);
    $unlocks = fn () => app(\App\Services\WaddamburoUnlocks::class)->locked($player->refresh());
    $this->mock(\App\Services\WaddamburoAiBattles::class, fn ($mock) => $mock->shouldReceive('progress')
        ->andReturn(['points' => 0, 'wins' => 0, 'rank' => 0, 'points_to_next' => 1]));

    // Title 152 (clear a credit of katakana-titled songs) gives kigurumi 56 チリチリロープ.
    expect($unlocks()['kigurumi'])->toContain(56);
    PlayerCosmetic::create(['baid' => $player->baid, 'game_version' => 'green', 'unlocked_titles' => [152]]);
    expect($unlocks()['kigurumi'])->not->toContain(56);
})->skip(fn () => ! file_exists(config('taiko_green.data_path').'/green/green_reward_table.json'), 'needs the Green reward table data file');
