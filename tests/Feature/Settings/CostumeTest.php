<?php

use App\Enums\TaikoGameVersion;
use App\Models\GameCard;
use App\Models\Player;
use App\Models\PlayerCosmetic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows costume page without access code', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/green/settings/costumes')
        ->assertOk()
        ->assertInertia(fn ($assert) => $assert
            ->component('settings/DonChan')
            ->where('hasAccessCode', false)
            ->where('versionLabel', 'GREEN')
            ->where('activePreset', 0)
            ->where('colorFace', 0)
            ->where('colorBody', 0)
            ->where('colorLimb', 0)
            ->has('presets', 3)
            ->has('sheet.slots.kigurumi')
            ->has('sheet.slots.puchi'));
});

it('shows saved presets for player with access code', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);
    GameCard::query()->create(['access_code' => '12345678901234567890', 'baid' => $player->baid]);

    PlayerCosmetic::query()->create([
        'baid' => $player->baid,
        'game_version' => 'green',
        'active_costume_preset' => 1,
        'costume_presets' => [
            ['costume_1' => 5, 'costume_2' => 0, 'costume_3' => 0, 'costume_5' => 0],
            ['costume_1' => 0, 'costume_2' => 3, 'costume_3' => 7, 'costume_5' => 2],
        ],
    ]);

    $this->actingAs($user)->get('/green/settings/costumes')
        ->assertOk()
        ->assertInertia(fn ($assert) => $assert
            ->component('settings/DonChan')
            ->where('hasAccessCode', true)
            ->where('activePreset', 1)
            ->where('presets.0.costume_1', 5)
            ->where('presets.1.costume_2', 3)
            ->where('presets.1.costume_3', 7)
            ->where('presets.1.costume_5', 2)
            // padded to three presets even though only two were stored
            ->where('presets.2.costume_1', 0));
});

it('saves presets and mirrors the worn preset into the equipped columns', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);
    GameCard::query()->create(['access_code' => '12345678901234567890', 'baid' => $player->baid]);

    $this->actingAs($user)
        ->patch('/green/settings/costumes', [
            'active_preset' => 2,
            'presets' => [
                ['costume_1' => 1, 'costume_2' => 1, 'costume_3' => 1, 'costume_5' => 1],
                ['costume_1' => 0, 'costume_2' => 2, 'costume_3' => 2, 'costume_5' => 2],
                ['costume_1' => 9, 'costume_2' => 0, 'costume_3' => 0, 'costume_5' => 0],
            ],
        ])
        ->assertRedirect();

    $cosmetic = PlayerCosmetic::resolve($player->baid, TaikoGameVersion::Green);
    expect($cosmetic->active_costume_preset)->toBe(2)
        ->and($cosmetic->costume_presets[1]['costume_2'])->toBe(2)
        // worn preset (index 2) mirrored into equipped costume_1..5
        ->and($cosmetic->costume_1)->toBe(9)
        ->and($cosmetic->costume_2)->toBe(0)
        ->and($cosmetic->costume_5)->toBe(0);
});
