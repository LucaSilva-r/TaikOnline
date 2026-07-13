<?php

use App\Models\GameCard;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('redirects customization page to DonChan page', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/green/settings/customize')
        ->assertRedirect('/green/settings/costumes');
});

it('shows DonChan page with access code and saved colors', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create([
        'user_id' => $user->id,
        'mydon_name' => 'どんちゃん',
        'color_face' => 10,
        'color_body' => 30,
        'color_limb' => 50,
    ]);
    GameCard::query()->create([
        'access_code' => '12345678901234567890',
        'baid' => $player->baid,
    ]);

    $response = $this->actingAs($user)->get('/green/settings/costumes');

    $response
        ->assertOk()
        ->assertInertia(fn ($assert) => $assert
            ->component('settings/DonChan')
            ->where('hasAccessCode', true)
            ->where('mydonName', 'どんちゃん')
            ->where('colorFace', 10)
            ->where('colorBody', 30)
            ->where('colorLimb', 50));
});

it('saves a hiragana DonChan name for the linked player', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create([
        'user_id' => $user->id,
        'mydon_name' => 'たいこ',
    ]);
    GameCard::query()->create([
        'access_code' => '12345678901234567890',
        'baid' => $player->baid,
    ]);

    $this->actingAs($user)
        ->patch('/green/settings/donchan-name', [
            'mydon_name' => 'どんちゃん',
        ])
        ->assertRedirect('/green/settings/costumes')
        ->assertSessionHasNoErrors();

    expect($player->refresh()->mydon_name)->toBe('どんちゃん');
});

it('rejects an invalid DonChan name', function (string $name): void {
    $user = User::factory()->create();
    $player = Player::query()->create([
        'user_id' => $user->id,
        'mydon_name' => 'たいこ',
    ]);
    GameCard::query()->create([
        'access_code' => '12345678901234567890',
        'baid' => $player->baid,
    ]);

    $this->actingAs($user)
        ->from('/green/settings/costumes')
        ->patch('/green/settings/donchan-name', [
            'mydon_name' => $name,
        ])
        ->assertRedirect('/green/settings/costumes')
        ->assertSessionHasErrors('mydon_name');

    expect($player->refresh()->mydon_name)->toBe('たいこ');
})->with([
    'empty' => '',
    'more than five hiragana' => 'あいうえおか',
    'romaji' => 'donchan',
    'katakana' => 'ドンチャン',
    'kanji' => '太鼓',
    'whitespace' => 'どん ちゃん',
    'punctuation' => 'どんちゃん!',
    'emoji' => 'どん🥁',
]);

it('does not update a DonChan name without a linked access code', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create([
        'user_id' => $user->id,
        'mydon_name' => 'たいこ',
    ]);

    $this->actingAs($user)
        ->patch('/green/settings/donchan-name', [
            'mydon_name' => 'どんちゃん',
        ])
        ->assertRedirect('/green/settings/costumes');

    expect($player->refresh()->mydon_name)->toBe('たいこ');
});

it('rejects DonChan name updates on unsupported game versions', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create([
        'user_id' => $user->id,
        'mydon_name' => 'たいこ',
    ]);
    GameCard::query()->create([
        'access_code' => '12345678901234567890',
        'baid' => $player->baid,
    ]);

    $this->actingAs($user)
        ->patch('/katsudon/settings/donchan-name', [
            'mydon_name' => 'どんちゃん',
        ])
        ->assertNotFound();

    expect($player->refresh()->mydon_name)->toBe('たいこ');
});

it('saves customization colors for player with access code', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create([
        'user_id' => $user->id,
        'color_face' => 0,
        'color_body' => 0,
        'color_limb' => 0,
    ]);
    GameCard::query()->create([
        'access_code' => '12345678901234567890',
        'baid' => $player->baid,
    ]);

    $this->actingAs($user)
        ->patch('/green/settings/customize', [
            'color_face' => 5,
            'color_body' => 25,
            'color_limb' => 45,
        ])
        ->assertRedirect('/green/settings/costumes');

    $updated = Player::query()->find($player->baid)->refresh();
    expect($updated->color_face)->toBe(5)
        ->and($updated->color_body)->toBe(25)
        ->and($updated->color_limb)->toBe(45);
});

it('does not save customization when no access code linked', function (): void {
    $user = User::factory()->create();
    Player::query()->create([
        'user_id' => $user->id,
        'color_face' => 0,
        'color_body' => 0,
        'color_limb' => 0,
    ]);

    $this->actingAs($user)
        ->patch('/green/settings/customize', [
            'color_face' => 10,
            'color_body' => 20,
            'color_limb' => 30,
        ])
        ->assertRedirect('/green/settings/costumes');

    $player = Player::query()->where('user_id', $user->id)->firstOrFail();
    expect($player->color_face)->toBe(0)
        ->and($player->color_body)->toBe(0)
        ->and($player->color_limb)->toBe(0);
});
