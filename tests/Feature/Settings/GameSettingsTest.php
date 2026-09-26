<?php

use App\Enums\TaikoGameVersion;
use App\Models\GameCard;
use App\Models\Player;
use App\Models\PlayerCosmetic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows game settings page without access code', function (): void {
    $user = User::factory()->create();
    $response = $this->actingAs($user)->get('/green/settings/game');

    $response
        ->assertOk()
        ->assertInertia(fn ($assert) => $assert
            ->component('settings/GameSettings')
            ->has('errors')
            ->where('hasAccessCode', false));
});

it('shows game settings page with access code and saved settings', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create([
        'user_id' => $user->id,
        'prefecture_id' => 13,
        'is_publish' => false,
        'disp_score_type' => 1,
        'disp_dan_type' => 1,
        'difficulty_played_course' => 3,
        'difficulty_played_star' => 8,
        'difficulty_played_sort' => 1,
    ]);
    GameCard::query()->create([
        'access_code' => '12345678901234567890',
        'baid' => $player->baid,
    ]);

    PlayerCosmetic::query()->create([
        'baid' => $player->baid,
        'game_version' => 'green',
        'default_tone_setting' => 4,
        'default_option_setting' => 10, // speed = 2, doron = 1, abekobe = 0, random = 0
    ]);

    $response = $this->actingAs($user)->get('/green/settings/game');

    $response
        ->assertOk()
        ->assertInertia(fn ($assert) => $assert
            ->component('settings/GameSettings')
            ->where('hasAccessCode', true)
            ->where('prefectureId', 13)
            ->where('isPublish', false)
            ->where('dispScoreType', 1)
            ->where('dispDanType', 1)
            ->where('difficultyPlayedCourse', 3)
            ->where('difficultyPlayedStar', 8)
            ->where('difficultyPlayedSort', 1)
            ->where('defaultToneSetting', 4)
            ->where('speed', 2)
            ->where('doron', 1)
            ->where('abekobe', 0)
            ->where('random', 0)
            ->where('supportsFolderSettings', true));
});

it('saves game settings for player with access code', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create([
        'user_id' => $user->id,
        'prefecture_id' => 0,
        'is_publish' => true,
        'disp_score_type' => 0,
        'disp_dan_type' => 0,
    ]);
    GameCard::query()->create([
        'access_code' => '12345678901234567890',
        'baid' => $player->baid,
    ]);

    $cosmetic = PlayerCosmetic::query()->create([
        'baid' => $player->baid,
        'game_version' => 'green',
        'default_tone_setting' => 0,
        'default_option_setting' => 0,
    ]);

    $this->actingAs($user)
        ->patch('/green/settings/game', [
            'prefecture_id' => 13,
            'is_publish' => false,
            'disp_score_type' => 5,
            'disp_dan_type' => 1,
            'difficulty_played_course' => 4,
            'difficulty_played_star' => 10,
            'difficulty_played_sort' => 1,
            'default_tone_setting' => 3,
            'speed' => 3,    // 3.0x (value 3)
            'doron' => 1,    // Doron enabled (+8)
            'abekobe' => 1,  // Abekobe enabled (+16)
            'random' => 2,   // Detarame enabled (+64)
            // Expected bitmask: 3 | (1 << 3) | (1 << 4) | (2 << 5) = 3 + 8 + 16 + 64 = 91
        ])
        ->assertRedirect();

    $player->refresh();
    expect($player->prefecture_id)->toBe(13)
        ->and($player->is_publish)->toBeFalse()
        ->and($player->disp_score_type)->toBe(5)
        ->and($player->disp_dan_type)->toBe(1)
        ->and($player->difficulty_played_course)->toBe(4)
        ->and($player->difficulty_played_star)->toBe(10)
        ->and($player->difficulty_played_sort)->toBe(1);

    $cosmetic->refresh();
    expect($cosmetic->default_option_setting)->toBe(91)
        ->and($cosmetic->default_tone_setting)->toBe(3);
});

it('syncs version-scoped settings to all game versions when toggles are on', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create([
        'user_id' => $user->id,
        'prefecture_id' => 0,
        'is_publish' => true,
        'disp_score_type' => 0,
        'disp_dan_type' => 0,
    ]);
    GameCard::query()->create([
        'access_code' => '12345678901234567890',
        'baid' => $player->baid,
    ]);

    $this->actingAs($user)
        ->patch('/green/settings/game', [
            'prefecture_id' => 0,
            'is_publish' => true,
            'disp_score_type' => 0,
            'disp_dan_type' => 0,
            'difficulty_played_course' => 0,
            'difficulty_played_star' => 0,
            'difficulty_played_sort' => 0,
            'sync_play_options' => 1,
            'sync_tone_settings' => 1,
            'default_tone_setting' => 5,
            'speed' => 3,
            'doron' => 1,
            'abekobe' => 0,
            'random' => 0,
            // bitmask: 3 | (1 << 3) = 11
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $versions = array_map(fn ($case) => $case->value, TaikoGameVersion::cases());
    $cosmetics = PlayerCosmetic::query()->where('baid', $player->baid)->get()->keyBy('game_version');

    expect($cosmetics)->toHaveCount(count($versions));

    foreach ($versions as $version) {
        expect($cosmetics[$version]->default_option_setting)->toBe(11)
            ->and($cosmetics[$version]->default_tone_setting)->toBe(5);
    }
});

it('only updates the current version when sync toggles are off', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create([
        'user_id' => $user->id,
        'prefecture_id' => 0,
        'is_publish' => true,
        'disp_score_type' => 0,
        'disp_dan_type' => 0,
    ]);
    GameCard::query()->create([
        'access_code' => '12345678901234567890',
        'baid' => $player->baid,
    ]);

    $this->actingAs($user)
        ->patch('/green/settings/game', [
            'prefecture_id' => 0,
            'is_publish' => true,
            'disp_score_type' => 0,
            'disp_dan_type' => 0,
            'difficulty_played_course' => 0,
            'difficulty_played_star' => 0,
            'difficulty_played_sort' => 0,
            'sync_play_options' => 0,
            'sync_tone_settings' => 0,
            'default_tone_setting' => 5,
            'speed' => 3,
            'doron' => 1,
            'abekobe' => 0,
            'random' => 0,
        ])
        ->assertRedirect();

    expect(PlayerCosmetic::query()->where('baid', $player->baid)->count())->toBe(1);
    expect(PlayerCosmetic::query()->where('baid', $player->baid)->first()->game_version)->toBe('green');
});

it('saves folder difficulty presets using official donderhiroba values', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create([
        'user_id' => $user->id,
        'prefecture_id' => 0,
        'is_publish' => true,
        'disp_score_type' => 0,
        'disp_dan_type' => 0,
    ]);
    GameCard::query()->create([
        'access_code' => '12345678901234567890',
        'baid' => $player->baid,
    ]);
    PlayerCosmetic::query()->create([
        'baid' => $player->baid,
        'game_version' => 'green',
        'default_tone_setting' => 0,
        'default_option_setting' => 0,
    ]);

    $this->actingAs($user)
        ->patch('/green/settings/game', [
            'prefecture_id' => 26, // Tokyo (official area_id)
            'is_publish' => true,
            'disp_score_type' => 0,
            'disp_dan_type' => 0,
            'difficulty_played_course' => 5,  // Ura Oni
            'difficulty_played_star' => 99,   // Set during game
            'difficulty_played_sort' => 4,    // Non-Donderful-combo first
            'default_tone_setting' => 0,
            'speed' => 0,
            'doron' => 0,
            'abekobe' => 0,
            'random' => 0,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $player->refresh();
    expect($player->prefecture_id)->toBe(26)
        ->and($player->difficulty_played_course)->toBe(5)
        ->and($player->difficulty_played_star)->toBe(99)
        ->and($player->difficulty_played_sort)->toBe(4);
});

it('ignores version-gated settings on Sorairo (no enso options, tone, or ranking difficulty)', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create([
        'user_id' => $user->id,
        'prefecture_id' => 0,
        'is_publish' => true,
        'disp_score_type' => 0,
        'disp_dan_type' => 0,
    ]);
    GameCard::query()->create([
        'access_code' => '12345678901234567890',
        'baid' => $player->baid,
    ]);

    $cosmetic = PlayerCosmetic::query()->create([
        'baid' => $player->baid,
        'game_version' => 'sorairo',
        'default_tone_setting' => 0,
        'default_option_setting' => 0,
    ]);

    $this->actingAs($user)
        ->patch('/sorairo/settings/game', [
            'prefecture_id' => 13,
            'is_publish' => false,
            'disp_score_type' => 5,
            'disp_dan_type' => 1,
            'difficulty_played_course' => 4,
            'difficulty_played_star' => 10,
            'difficulty_played_sort' => 1,
            'default_tone_setting' => 3,
            'speed' => 3,
            'doron' => 1,
            'abekobe' => 1,
            'random' => 2,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    // Shared settings still apply; ranking difficulty is unsupported pre-Murasaki.
    $player->refresh();
    expect($player->prefecture_id)->toBe(13)
        ->and($player->is_publish)->toBeFalse()
        ->and($player->disp_score_type)->toBe(0); // unchanged: unsupported on Sorairo

    // Enso options (Momoiro+) and tone (Murasaki+) are not written on Sorairo.
    $cosmetic->refresh();
    expect($cosmetic->default_option_setting)->toBe(0)
        ->and($cosmetic->default_tone_setting)->toBe(0);
});

it('gates publicity and folder presets on Katsudon (fields absent, never written)', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create([
        'user_id' => $user->id,
        'prefecture_id' => 0,
        'is_publish' => true,
        'disp_dan_type' => 0,
        'difficulty_played_course' => 2,
        'difficulty_played_star' => 5,
        'difficulty_played_sort' => 3,
    ]);
    GameCard::query()->create([
        'access_code' => '12345678901234567890',
        'baid' => $player->baid,
    ]);

    // Katsudon UI omits the publicity toggle (Sorairo+) and folder presets (White+),
    // so the request arrives without those fields and must still validate.
    $this->actingAs($user)
        ->patch('/katsudon/settings/game', [
            'prefecture_id' => 13,
            'disp_dan_type' => 1,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $player->refresh();
    expect($player->prefecture_id)->toBe(13)
        ->and($player->disp_dan_type)->toBe(1)
        // Publicity unsupported pre-Sorairo: unchanged.
        ->and($player->is_publish)->toBeTrue()
        // Folder presets unsupported pre-White: unchanged.
        ->and($player->difficulty_played_course)->toBe(2)
        ->and($player->difficulty_played_star)->toBe(5)
        ->and($player->difficulty_played_sort)->toBe(3);
});

it('does not expose the DonChan costume support flag for older versions', function (): void {
    expect(TaikoGameVersion::Sorairo->featureSupport()['costumeSlots'])->toBeFalse()
        ->and(TaikoGameVersion::Momoiro->featureSupport()['costumeSlots'])->toBeTrue();
});

it('saves enso options on Momoiro but still gates tone (Murasaki+)', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create([
        'user_id' => $user->id,
        'prefecture_id' => 0,
        'is_publish' => true,
        'disp_score_type' => 0,
        'disp_dan_type' => 0,
    ]);
    GameCard::query()->create([
        'access_code' => '12345678901234567890',
        'baid' => $player->baid,
    ]);
    $cosmetic = PlayerCosmetic::query()->create([
        'baid' => $player->baid,
        'game_version' => 'momoiro',
        'default_tone_setting' => 0,
        'default_option_setting' => 0,
    ]);

    $this->actingAs($user)
        ->patch('/momoiro/settings/game', [
            'prefecture_id' => 0,
            'is_publish' => true,
            'disp_dan_type' => 0,
            'difficulty_played_course' => 0,
            'difficulty_played_star' => 0,
            'difficulty_played_sort' => 0,
            'default_tone_setting' => 3,
            'speed' => 3,
            'doron' => 1,
            'abekobe' => 0,
            'random' => 0,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $cosmetic->refresh();
    expect($cosmetic->default_option_setting)->toBe(11) // enso options saved
        ->and($cosmetic->default_tone_setting)->toBe(0); // tone gated off
});
