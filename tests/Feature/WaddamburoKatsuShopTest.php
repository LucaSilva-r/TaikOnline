<?php

use App\Models\Player;
use App\Models\PlayerShopItem;
use App\Models\User;
use App\Services\WaddamburoKatsuShop;
use App\Services\WaddamburoMedals;
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

    \App\Models\WdbMedalCredit::query()->where('baid', $player->baid)->update(['katsu' => 600]);
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
