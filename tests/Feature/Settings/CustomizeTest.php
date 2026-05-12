<?php

use App\Models\GameCard;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows customization page without access code', function (): void {
    $user = User::factory()->create();
    $response = $this->actingAs($user)->get('/settings/customize');

    $response
        ->assertOk()
        ->assertInertia(fn ($assert) => $assert
            ->component('settings/Customize')
            ->has('errors')
            ->where('hasAccessCode', false));
});

it('shows customization page with access code and saved colors', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create([
        'user_id' => $user->id,
        'color_face' => 10,
        'color_body' => 30,
        'color_limb' => 50,
    ]);
    GameCard::query()->create([
        'access_code' => '12345678901234567890',
        'baid' => $player->baid,
    ]);

    $response = $this->actingAs($user)->get('/settings/customize');

    $response
        ->assertOk()
        ->assertInertia(fn ($assert) => $assert
            ->component('settings/Customize')
            ->where('hasAccessCode', true)
            ->where('colorFace', 10)
            ->where('colorBody', 30)
            ->where('colorLimb', 50));
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
        ->patch('/settings/customize', [
            'color_face' => 5,
            'color_body' => 25,
            'color_limb' => 45,
        ])
        ->assertRedirect();

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
        ->patch('/settings/customize', [
            'color_face' => 10,
            'color_body' => 20,
            'color_limb' => 30,
        ])
        ->assertRedirect();

    $player = Player::query()->where('user_id', $user->id)->firstOrFail();
    expect($player->color_face)->toBe(0)
        ->and($player->color_body)->toBe(0)
        ->and($player->color_limb)->toBe(0);
});
