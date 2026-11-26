<?php

use App\Enums\TaikoGameVersion;
use App\Models\GameCard;
use App\Models\Player;
use App\Models\PlayerCosmetic;
use App\Models\User;
use App\Services\WaddamburoKatsuShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

it('shows costume page without access code', function (): void {
    $user = User::factory()->create();
    $sheetPath = storage_path('app/public/costumes/green/sheet.json');
    $originalSheet = File::exists($sheetPath) ? File::get($sheetPath) : null;

    File::ensureDirectoryExists(dirname($sheetPath));
    File::put($sheetPath, json_encode([
        'cell' => 96,
        'sheet' => [96, 192],
        'slots' => [
            'kigurumi' => [['id' => 1, 'x' => 0, 'y' => 0]],
            'puchi' => [['id' => 2, 'x' => 0, 'y' => 96]],
        ],
    ]));

    try {
        $this->actingAs($user)->get('/green/settings/costumes')
            ->assertOk()
            ->assertInertia(fn ($assert) => $assert
                ->component('settings/DonChan')
                ->where('hasAccessCode', false)
                ->where('versionLabel', 'GREEN')
                ->where('activePreset', 0)
                ->where('mydonName', '')
                ->where('colorFace', 0)
                ->where('colorBody', 0)
                ->where('colorLimb', 0)
                ->has('presets', 3)
                ->where('sheet.url', fn (string $url): bool => str_starts_with($url, '/storage/costumes/green/sheet.png?v='))
                ->has('sheet.slots.kigurumi')
                ->has('sheet.slots.puchi'));
    } finally {
        if ($originalSheet === null) {
            File::delete($sheetPath);
        } else {
            File::put($sheetPath, $originalSheet);
        }
    }
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

it('marks the costume page unsupported for pre-Momoiro versions', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/katsudon/settings/costumes')
        ->assertOk()
        ->assertInertia(fn ($assert) => $assert
            ->component('settings/DonChan')
            ->where('supported', false)
            ->where('versionLabel', 'KATSUDON'));
});

it('rejects costume updates on pre-Momoiro versions', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);
    GameCard::query()->create(['access_code' => '12345678901234567890', 'baid' => $player->baid]);

    $this->actingAs($user)
        ->patch('/katsudon/settings/costumes', [
            'active_preset' => 0,
            'presets' => [
                ['costume_1' => 1, 'costume_2' => 0, 'costume_3' => 0, 'costume_5' => 0],
                ['costume_1' => 0, 'costume_2' => 0, 'costume_3' => 0, 'costume_5' => 0],
                ['costume_1' => 0, 'costume_2' => 0, 'costume_3' => 0, 'costume_5' => 0],
            ],
        ])
        ->assertNotFound();

    expect(PlayerCosmetic::query()->where('baid', $player->baid)->count())->toBe(0);
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
                ['costume_1' => 9, 'costume_2' => 0, 'costume_3' => 0, 'costume_5' => 0, 'costume_4' => 26],
            ],
        ])
        ->assertRedirect();

    $cosmetic = PlayerCosmetic::resolve($player->baid, TaikoGameVersion::Green);
    expect($cosmetic->active_costume_preset)->toBe(2)
        ->and($cosmetic->costume_presets[1]['costume_2'])->toBe(2)
        // worn preset (index 2) mirrored into equipped costume_1..5
        ->and($cosmetic->costume_1)->toBe(9)
        ->and($cosmetic->costume_2)->toBe(0)
        ->and($cosmetic->costume_5)->toBe(0)
        // make (slot 4) is optional per preset and mirrored too
        ->and($cosmetic->costume_presets[0]['costume_4'])->toBe(0)
        ->and($cosmetic->costume_4)->toBe(26);
});

it('can save a Waddamburo preset when a different preset contains a newly locked item', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);
    GameCard::query()->create(['access_code' => '12345678901234567890', 'baid' => $player->baid]);
    $cosmetic = PlayerCosmetic::query()->create([
        'baid' => $player->baid,
        'game_version' => PlayerCosmetic::WADDAMBURO,
        'wdb_earned_only' => true,
        'costume_presets' => [[], ['costume_4' => 26]],
    ]);
    PlayerCosmetic::query()->create([
        'baid' => $player->baid,
        'game_version' => TaikoGameVersion::Green->value,
        'unlocked_costumes' => ['1' => [9]],
    ]);
    $this->mock(WaddamburoKatsuShop::class, fn ($shop) => $shop->shouldReceive('items')
        ->andReturn([[6, 26, 'Test make', 'green']]));
    $url = '/waddamburo/settings/costumes';
    $this->actingAs($user);

    $page = $this->get($url)
        ->assertInertia(fn ($assert) => $assert
            ->where('hasLockedPresetItems', true)
            ->where('presets.1.costume_4', 0));
    $presets = $page->viewData('page')['props']['presets'];
    $presets[0]['costume_1'] = 9;
    $this->postJson($url.'?_method=PATCH', ['active_preset' => 0, 'presets' => $presets])
        ->assertRedirect($url)
        ->assertSessionHasNoErrors();

    $cosmetic->refresh();
    expect($cosmetic->costume_1)->toBe(9)
        ->and($cosmetic->costume_presets[1]['costume_4'])->toBe(26);
});

it('hides unowned preset parts in earned-only mode and restores them in unlock-everything mode', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);
    GameCard::query()->create(['access_code' => '12345678901234567890', 'baid' => $player->baid]);
    PlayerCosmetic::create([
        'baid' => $player->baid,
        'game_version' => TaikoGameVersion::Green->value,
        'unlocked_costumes' => ['2' => [53]],
    ]);
    $outfit = ['costume_1' => 9, 'costume_2' => 53, 'costume_3' => 132, 'costume_4' => 26, 'costume_5' => 4];
    PlayerCosmetic::create([
        'baid' => $player->baid,
        'game_version' => PlayerCosmetic::WADDAMBURO,
        'wdb_earned_only' => false,
        'active_costume_preset' => 1,
        'costume_presets' => [['costume_2' => 119], $outfit, ['costume_4' => 26]],
        ...$outfit,
    ]);
    $this->mock(WaddamburoKatsuShop::class, fn ($shop) => $shop->shouldReceive('items')->andReturn([
        [3, 9, 'Test suit', 'green'],
        [5, 53, 'Owned head', 'green'],
        [4, 132, 'Test body', 'green'],
        [6, 26, 'Test make', 'green'],
        [7, 4, 'Test puchi', 'green'],
    ]));
    $token = $user->createToken('costume-test', ['wdb'])->plainTextToken;
    $url = '/waddamburo/settings/costumes';

    $this->actingAs($user)->patch('/waddamburo/settings/unlock-mode', ['earned_only' => true])->assertRedirect($url);
    $this->get($url)->assertInertia(fn ($assert) => $assert
        ->where('activePreset', 1)
        ->where('presets.0.costume_2', 0)
        ->where('presets.1.costume_2', 53)
        ->where('presets.1.costume_4', 0));
    $this->withToken($token)->getJson('/api/wdb/me')->assertOk()
        ->assertJsonPath('look.costume', [0, 53, 0, 0, 0])
        ->assertJsonPath('look.presets', [[0, 0, 0, 0, 0], [0, 53, 0, 0, 0], [0, 0, 0, 0, 0]]);

    $this->patch('/waddamburo/settings/unlock-mode', ['earned_only' => false])->assertRedirect($url);
    $this->get($url)->assertInertia(fn ($assert) => $assert
        ->where('activePreset', 1)
        ->where('hasLockedPresetItems', false)
        ->where('presets.0.costume_2', 119)
        ->where('presets.1.costume_4', 26));
    $this->withToken($token)->getJson('/api/wdb/me')->assertOk()
        ->assertJsonPath('look.costume', [9, 53, 132, 26, 4])
        ->assertJsonPath('look.presets', [[0, 119, 0, 0, 0], [9, 53, 132, 4, 26], [0, 0, 0, 0, 26]]);
});

it('shows the Don-chan name setting on game versions but not on Waddamburo', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/green/settings/costumes')
        ->assertInertia(fn ($assert) => $assert->where('showDonChanName', true)->where('versionLabel', 'GREEN'));
    $this->actingAs($user)->get('/waddamburo/settings/costumes')
        ->assertInertia(fn ($assert) => $assert->where('showDonChanName', false)->where('versionLabel', 'Waddamburo'));
});

it('maps each version\'s costume ids to the preview models, the dan ranks shifting the kigurumi after 36', function (): void {
    $map = json_decode(file_get_contents(database_path('data/donchan-costume-map.json')), true);

    expect($map['green']['kigurumi'])->toMatchArray(['35' => 35, '36' => 36, '37' => 62, '40' => null])
        ->and($map['green']['head']['139'])->toBe(139)
        ->and($map['white']['head'])->toMatchArray(['111' => null, '112' => 122])
        ->and(array_keys($map))->toContain('green', 'white', 'blue', 'sorairo');
});
