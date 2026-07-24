<?php

use App\Models\GameCard;
use App\Models\Player;
use App\Models\User;
use App\Services\MifareAccessCodeService;

test('profile page exposes linked access code', function () {
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);
    GameCard::query()->create([
        'access_code' => 'AC-123456',
        'baid' => $player->baid,
    ]);

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('accessCode', 'AC-123456'));
});

test('user cannot bind an access code', function () {
    $user = User::factory()->create();
    $player = Player::query()->create();
    GameCard::query()->create([
        'access_code' => 'AC-AAA',
        'baid' => $player->baid,
    ]);

    $this->actingAs($user)
        ->patch('/green/settings/access-code', ['access_code' => 'AC-AAA'])
        ->assertNotFound();

    expect($player->refresh()->user_id)->toBeNull();
});

test('invalid access codes cannot be submitted from user settings', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/green/settings/access-code', ['access_code' => 'NOPE'])
        ->assertNotFound();
});

test('users cannot bind a real mifare card not yet in the database', function () {
    configure_nbgic_test_profiles();

    $user = User::factory()->create();
    $accessCode = app(MifareAccessCodeService::class)->generate(profile: 0, cardId: 0xABCDEF01);

    $this->actingAs($user)
        ->patch('/green/settings/access-code', ['access_code' => $accessCode])
        ->assertNotFound();

    expect(GameCard::query()->whereKey($accessCode)->exists())->toBeFalse();
});

test('non-mifare access codes cannot be submitted from user settings', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/green/settings/access-code', ['access_code' => '99999999999999999999'])
        ->assertNotFound();
});

test('users cannot claim an access code linked to another user', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $player = Player::query()->create(['user_id' => $owner->id]);
    GameCard::query()->create([
        'access_code' => 'AC-OWNED',
        'baid' => $player->baid,
    ]);

    $this->actingAs($other)
        ->patch('/green/settings/access-code', ['access_code' => 'AC-OWNED'])
        ->assertNotFound();

    expect($player->refresh()->user_id)->toBe($owner->id);
});

test('users cannot replace their linked access code', function () {
    $user = User::factory()->create();
    $existingPlayer = Player::query()->create(['user_id' => $user->id]);
    GameCard::query()->create([
        'access_code' => 'AC-OLD',
        'baid' => $existingPlayer->baid,
    ]);

    $newPlayer = Player::query()->create();
    GameCard::query()->create([
        'access_code' => 'AC-NEW',
        'baid' => $newPlayer->baid,
    ]);

    $this->actingAs($user)
        ->patch('/green/settings/access-code', ['access_code' => 'AC-NEW'])
        ->assertNotFound();

    expect($existingPlayer->refresh()->user_id)->toBe($user->id)
        ->and($newPlayer->refresh()->user_id)->toBeNull();
});

test('user cannot unbind their access code', function () {
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);
    GameCard::query()->create([
        'access_code' => 'AC-X',
        'baid' => $player->baid,
    ]);

    $this->actingAs($user)
        ->delete('/green/settings/access-code')
        ->assertNotFound();

    expect($player->refresh()->user_id)->toBe($user->id);
});
