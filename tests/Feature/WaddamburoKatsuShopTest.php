<?php

use App\Models\GameCard;
use App\Models\Player;
use App\Models\PlayerCosmetic;
use App\Models\PlayerShopItem;
use App\Models\User;
use App\Models\WdbMedalCredit;
use App\Services\WaddamburoKatsuShop;
use App\Services\WaddamburoMedals;
use App\Services\WaddamburoTitleGacha;
use App\Services\WaddamburoUnlocks;
use Illuminate\Support\Str;

it('pays Katsu Medals per credit and sells the Katsu shop on the website', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);
    $token = $user->createToken('medals', ['wdb'])->plainTextToken;
    $song = fn (string $at, string $kind = 'song') => ['id' => (string) Str::uuid(), 'played_at' => $at, 'kind' => $kind];
    $shop = app(WaddamburoKatsuShop::class);

    // A home credit (three songs) gives 100; a dan course none.
    $this->withToken($token)->postJson('/api/wdb/medals/songs', ['songs' => [
        $song('2026-11-20T10:00:00+01:00'), $song('2026-11-20T10:05:00+01:00'), $song('2026-11-20T10:10:00+01:00'),
        $song('2026-11-20T11:00:00+01:00', 'dan'),
    ]])->assertOk();
    expect($shop->wallet($player->baid))->toBe(100);

    [$type, $id] = collect($shop->items())->first(fn (array $item) => $item[0] === 3);
    $this->actingAs($user)->post('/waddamburo/katsu-shop', ['type' => $type, 'id' => $id])->assertRedirect();
    expect(PlayerShopItem::query()->where('baid', $player->baid)->count())->toBe(0); // 500 needed

    WdbMedalCredit::query()->where('baid', $player->baid)->update(['katsu' => 600]);
    $donBefore = app(WaddamburoMedals::class)->wallet($player->baid);
    $this->actingAs($user)->post('/waddamburo/katsu-shop', ['type' => $type, 'id' => $id])->assertRedirect();
    expect(PlayerShopItem::query()->where('baid', $player->baid)->where('season_id', WaddamburoKatsuShop::SEASON)->count())->toBe(1)
        ->and($shop->wallet($player->baid))->toBe(1200 - 500)
        ->and(app(WaddamburoMedals::class)->wallet($player->baid))->toBe($donBefore);

    // Owned now; and the page lists it so.
    $this->actingAs($user)->post('/waddamburo/katsu-shop', ['type' => $type, 'id' => $id]);
    expect(PlayerShopItem::query()->where('baid', $player->baid)->count())->toBe(1);
    $this->actingAs($user)->get('/waddamburo/katsu-shop')->assertOk()
        ->assertInertia(fn ($page) => $page->component('WaddamburoKatsuShop')->where('medals', 700));
})->skip(fn () => ! file_exists(config('taiko_green.data_path').'/green/green_katsu_shop.json'), 'needs the Katsu shop data file');

it('pulls the title parts gacha for Katsu Medals', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);
    WdbMedalCredit::query()->create(['baid' => $player->baid, 'local_day' => '2026-11-20', 'season_id' => 0,
        'medals' => 100, 'daily' => false, 'weekly' => false, 'monthly' => false, 'katsu' => 110]);
    $shop = app(WaddamburoKatsuShop::class);

    // Five pulls: 100; then one more is 20, with 10 left: refused.
    $this->actingAs($user)->post('/waddamburo/gacha', ['banner' => 'G01', 'count' => 5])->assertRedirect();
    $parts = PlayerCosmetic::query()->where('baid', $player->baid)->where('game_version', 'waddamburo')->value('wdb_title_parts');
    expect($shop->wallet($player->baid))->toBe(10)
        ->and($parts)->not->toBeEmpty()
        ->and(collect($parts)->every(fn (string $key) => str_starts_with($key, 'G01:')))->toBeTrue();
    $this->actingAs($user)->post('/waddamburo/gacha', ['banner' => 'G01', 'count' => 1]);
    expect($shop->wallet($player->baid))->toBe(10);
    $this->actingAs($user)->get('/waddamburo/gacha')->assertOk()
        ->assertInertia(fn ($page) => $page->component('WaddamburoGacha')->where('medals', 10)->has('banners', 14));
})->skip(fn () => ! file_exists(config('taiko_green.data_path').'/green/green_title_parts.json'), 'needs the title parts data file');

it('builds a title from owned parts, as Donder Hiroba did', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);
    GameCard::query()->create(['access_code' => '12345678901234567892', 'baid' => $player->baid]);
    PlayerCosmetic::query()->create(['baid' => $player->baid, 'game_version' => 'waddamburo', 'wdb_earned_only' => true,
        'wdb_title_parts' => ['G01:75', 'G01:93'], 'unlocked_titles' => []]); // 地元, スーパー
    $gacha = app(WaddamburoTitleGacha::class);
    $of = array_search('の', $gacha->data()['b'], true);
    $title = fn () => PlayerCosmetic::query()->where('baid', $player->baid)->where('game_version', 'waddamburo')->value('title');

    $this->actingAs($user)->patch('/waddamburo/settings/donchan-title-parts', ['first' => 'G01:75', 'connector' => $of, 'second' => 'G01:93', 'titleplate_id' => 1])
        ->assertRedirect();
    expect($title())->toBe('地元のスーパー');

    // A part not owned, or free text in Only what I earned: refused.
    $this->actingAs($user)->patch('/waddamburo/settings/donchan-title-parts', ['first' => 'G01:0', 'connector' => 0, 'second' => null, 'titleplate_id' => 0])
        ->assertStatus(422);
    $this->actingAs($user)->patch('/waddamburo/settings/donchan-title', ['title' => 'anything', 'titleplate_id' => 0])->assertStatus(422);
    expect($title())->toBe('地元のスーパー');
})->skip(fn () => ! file_exists(config('taiko_green.data_path').'/green/green_title_parts.json'), 'needs the title parts data file');

it('sells the puchi charas in the Katsu shop and keeps them locked until bought', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);
    PlayerCosmetic::query()->create(['baid' => $player->baid, 'game_version' => 'waddamburo', 'wdb_earned_only' => true, 'unlocked_titles' => []]);
    $puchi = collect(app(WaddamburoKatsuShop::class)->items())->where(0, 7);
    expect($puchi->count())->toBe(7) // Green's Katsu puchi; the collab ones are event goods (WaddamburoEvents)
        ->and(app(WaddamburoUnlocks::class)->locked($player)['puchi'])->toContain($puchi->first()[1]);
})->skip(fn () => ! file_exists(config('taiko_green.data_path').'/green/green_katsu_shop.json'), 'needs the Katsu shop data file');
