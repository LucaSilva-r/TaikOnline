<?php

use App\Models\GameCard;
use App\Models\Player;
use App\Models\User;

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

test('user can bind an access code', function () {
    $user = User::factory()->create();
    $player = Player::query()->create();
    GameCard::query()->create([
        'access_code' => 'AC-AAA',
        'baid' => $player->baid,
    ]);

    $this->actingAs($user)
        ->patch(route('access-code.update'), ['access_code' => 'AC-AAA'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($player->refresh()->user_id)->toBe($user->id);
});

test('binding fails for unknown access code', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('profile.edit'))
        ->patch(route('access-code.update'), ['access_code' => 'NOPE'])
        ->assertSessionHasErrors('access_code');
});

test('binding fails when access code already linked to other user', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $player = Player::query()->create(['user_id' => $owner->id]);
    GameCard::query()->create([
        'access_code' => 'AC-OWNED',
        'baid' => $player->baid,
    ]);

    $this->actingAs($other)
        ->from(route('profile.edit'))
        ->patch(route('access-code.update'), ['access_code' => 'AC-OWNED'])
        ->assertSessionHasErrors('access_code');

    expect($player->refresh()->user_id)->toBe($owner->id);
});

test('binding fails when user already has a linked code', function () {
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
        ->from(route('profile.edit'))
        ->patch(route('access-code.update'), ['access_code' => 'AC-NEW'])
        ->assertSessionHasErrors('access_code');

    expect($newPlayer->refresh()->user_id)->toBeNull();
});

test('user can unbind their access code', function () {
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);
    GameCard::query()->create([
        'access_code' => 'AC-X',
        'baid' => $player->baid,
    ]);

    $this->actingAs($user)
        ->delete(route('access-code.destroy'))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($player->refresh()->user_id)->toBeNull();
});
