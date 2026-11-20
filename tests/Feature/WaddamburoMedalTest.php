<?php

use App\Models\Player;
use App\Models\User;
use App\Models\WdbCabinet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function medal_fixture(): array
{
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);

    return [$player, $user->createToken('medals', ['wdb'])->plainTextToken];
}

function medal_song(string $at, array $overrides = []): array
{
    return ['id' => (string) Str::uuid(), 'played_at' => $at, 'kind' => 'song', ...$overrides];
}

it('pays a home credit every three songs, with Green\'s daily, weekly and monthly bonuses', function (): void {
    [$player, $token] = medal_fixture();
    $send = fn (array $songs) => $this->withToken($token)->postJson('/api/wdb/medals/songs', ['songs' => $songs])->assertOk();

    // Two songs: no credit yet.
    $send([medal_song('2026-11-19T20:00:00+01:00'), medal_song('2026-11-19T20:05:00+01:00')])
        ->assertJson(['credits' => [], 'wallet' => 0]);

    // The third song: the first credit ever has every bonus (100 + 50 + 100 + 300).
    $third = medal_song('2026-11-19T20:10:00+01:00');
    $send([$third])->assertJson(['accepted' => [$third['id']], 'credits' => [
        ['medals' => 550, 'daily' => true, 'weekly' => true, 'monthly' => true],
    ], 'wallet' => 550]);

    // A re-send counts nothing; the credit was already announced.
    $send([$third])->assertJson(['accepted' => [$third['id']], 'credits' => [], 'wallet' => 550]);

    // Same day: 100. The next day by the player's own clock (still the 19th in UTC): daily. The next
    // week (Monday): daily + weekly. A new month: every bonus.
    $credit = fn (string $at) => $send([medal_song($at), medal_song($at), medal_song($at)])->json('credits.0.medals');
    expect($credit('2026-11-19T21:00:00+01:00'))->toBe(100)
        ->and($credit('2026-11-20T00:30:00+01:00'))->toBe(150)
        ->and($credit('2026-11-23T18:00:00+01:00'))->toBe(250)
        ->and($credit('2026-12-01T18:00:00+01:00'))->toBe(550);

    expect($player->refresh()->total_get_donmedal)->toBe(550 + 100 + 150 + 250 + 550);
});

it('pays an arcade credit once per credit id and a dan course on its own', function (): void {
    [$player, $token] = medal_fixture();
    $credit = (string) Str::uuid();

    $this->withToken($token)->postJson('/api/wdb/medals/songs', ['songs' => [
        medal_song('2026-11-19T20:00:00+01:00', ['credit_id' => $credit]),
        medal_song('2026-11-19T20:05:00+01:00', ['credit_id' => $credit]),
        medal_song('2026-11-19T20:30:00+01:00', ['kind' => 'dan']),
    ]])->assertOk()->assertJson(['credits' => [['medals' => 550], ['medals' => 100]], 'wallet' => 650]);
});

it('takes a cabinet\'s songs for the card on each song', function (): void {
    [$player] = medal_fixture();
    [$cabinet, $plain] = WdbCabinet::issue('Test cabinet', null);

    $this->withToken($plain)->postJson('/api/wdb/medals/songs', ['songs' => [
        medal_song('2026-11-19T20:00:00+09:00', ['baid' => $player->baid, 'credit_id' => (string) Str::uuid()]),
    ]])->assertOk()->assertJson(['credits' => [], 'wallet' => null]);

    expect($player->refresh()->total_get_donmedal)->toBe(550);
});

it('tells the game the wallet and the last paid day in the profile', function (): void {
    [$player, $token] = medal_fixture();
    $this->withToken($token)->getJson('/api/wdb/me')->assertOk()->assertJsonPath('medals', 0)->assertJsonPath('last_medal_day', null);

    $at = '2026-11-19T20:00:00+01:00';
    $this->withToken($token)->postJson('/api/wdb/medals/songs', ['songs' => [medal_song($at), medal_song($at), medal_song($at)]]);
    $this->withToken($token)->getJson('/api/wdb/me')->assertOk()->assertJsonPath('medals', 550)
        ->assertJsonPath('last_medal_day', '2026-11-19');
});

it('sells this season\'s goods for medals, once each', function (): void {
    $this->travelTo('2026-11-19 12:00:00'); // the fall shop: 4 costumes at 500, 4 songs at 1300-1500
    [$player, $token] = medal_fixture();
    $at = '2026-11-19T20:00:00+01:00';
    $this->withToken($token)->postJson('/api/wdb/medals/songs', ['songs' => [medal_song($at), medal_song($at), medal_song($at)]]);
    \App\Models\Song::query()->create(['version' => 'green', 'song_no' => 855, 'music_id' => 'shopsong', 'unique_id' => 855, 'title' => 'Shop song', 'genre' => 'namco_original', 'partsset' => 'A3']);

    $this->withToken($token)->getJson('/api/wdb/shop')->assertOk()
        ->assertJsonPath('season_id', 3)->assertJsonPath('telop', 'Fall reward shop')->assertJsonPath('medals', 550)
        ->assertJsonPath('goods.0', ['no' => 1, 'type' => 5, 'id' => 53, 'price' => 500, 'owned' => false, 'music_id' => null])
        ->assertJsonPath('goods.4.music_id', 'shopsong');

    $buy = fn (int $no) => $this->withToken($token)->postJson('/api/wdb/shop/purchase', ['no' => $no]);
    $buy(1)->assertOk()->assertJson(['result' => 'ok', 'medals' => 50]);
    $buy(1)->assertStatus(409)->assertJson(['result' => 'owned']);
    $buy(2)->assertStatus(409)->assertJson(['result' => 'short', 'medals' => 50]);

    $this->withToken($token)->getJson('/api/wdb/shop')->assertJsonPath('goods.0.owned', true)->assertJsonPath('goods.1.owned', false);
    expect($player->refresh()->total_use_donmedal)->toBe(500);
});

it('starts the wallet again each shop season, and pays every bonus on the season\'s first credit', function (): void {
    $this->travelTo('2026-11-19 12:00:00'); // fall (from 09-04)
    [$player, $token] = medal_fixture();
    $send = fn (string $at) => $this->withToken($token)->postJson('/api/wdb/medals/songs',
        ['songs' => [medal_song($at), medal_song($at), medal_song($at)]])->assertOk();
    $send('2026-11-19T20:00:00+01:00');
    $this->withToken($token)->getJson('/api/wdb/me')->assertJsonPath('medals', 550);

    // A last fall credit on Friday 12-04; winter starts on 12-05: the fall medals are gone, and Sunday's credit (same week
    // and month as Friday's) still pays every bonus, being the season's first.
    $this->travelTo('2026-12-04 12:00:00');
    $send('2026-12-04T20:00:00+01:00');
    $this->travelTo('2026-12-06 12:00:00');
    $this->withToken($token)->getJson('/api/wdb/me')->assertJsonPath('medals', 0);
    $send('2026-12-06T10:00:00+01:00')->assertJson(['credits' => [['medals' => 550, 'daily' => true, 'weekly' => true, 'monthly' => true]]]);
    $this->withToken($token)->getJson('/api/wdb/me')->assertJsonPath('medals', 550);
});

it('fills the shop with the day\'s goods Green gives no way to earn, and locks them until bought', function (): void {
    $this->travelTo('2026-11-19 12:00:00'); // the fall shop: 4 costumes and 4 songs; the pool adds 7 costumes and 2 songs
    [$player, $token] = medal_fixture();
    $shop = fn () => $this->withToken($token)->getJson('/api/wdb/shop')->assertOk()->json('goods');
    $pool = app(\App\Services\WaddamburoShopPool::class);
    $today = $shop();
    $songs = fn (array $goods) => count(array_filter($goods, fn (array $item) => $item['type'] === 1));
    // reward_shop's areas: 11 costume boxes, 6 song boxes; nothing spills over.
    expect($today)->toHaveCount(17)->and($today[8]['no'])->toBe(9)
        ->and($songs($today))->toBe(6)->and(count($today) - $songs($today))->toBe(11);

    // Tomorrow: the next ones; a client still showing today's goods is not sold tomorrow's.
    $this->travelTo('2026-11-20 00:05:00');
    expect($shop()[8])->not->toBe($today[8]);
    $this->withToken($token)->postJson('/api/wdb/shop/purchase', ['no' => 9, 'type' => $today[8]['type'], 'id' => $today[8]['id']])
        ->assertNotFound();

    // Pool goods lock in "Only what I earned" (songs need their stock row to show up as musicids).
    \App\Models\PlayerCosmetic::create(['baid' => $player->baid, 'game_version' => 'waddamburo', 'wdb_earned_only' => true]);
    [$type, $id] = collect($pool->items())->first(fn (array $item) => $item[0] !== 1);
    expect(app(\App\Services\WaddamburoUnlocks::class)->locked($player->refresh())[\App\Services\WaddamburoUnlocks::TYPES[$type]])->toContain($id);
})->skip(fn () => ! file_exists(config('taiko_green.data_path').'/green/green_shop_pool.json'), 'needs the shop pool data file');

it('checks Green\'s condition titles at the end of a home credit', function (): void {
    [$player, $token] = medal_fixture();
    // Lemon (ynzlmn): J-POP, an alphabet title, the FOOD tag (60).
    $chart = \App\Models\WdbChart::query()->create(['sha256' => str_repeat('c', 64), 'notes' => 'x', 'title' => 'Lemon', 'course' => 3, 'source' => 'Stock']);
    $songId = DB::table('wdb_songs')->insertGetId(['family' => 'Namco', 'song_key' => 'ynzlmn', 'title' => 'Lemon', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('wdb_chart_song')->insert(['wdb_chart_id' => $chart->id, 'wdb_song_id' => $songId, 'source' => 'Stock', 'release' => '']);
    $song = function (bool $cleared, string $at) use ($player, $chart): array {
        $id = (string) Str::uuid();
        if ($cleared) {
            \App\Models\WdbPlay::query()->create([
                'id' => $id, 'baid' => $player->baid, 'wdb_chart_id' => $chart->id, 'mode' => 'normal', 'course' => 3, 'score' => 1,
                'great' => 100, 'good' => 0, 'miss' => 0, 'max_combo' => 100, 'rolls' => 0, 'gauge' => 50, 'cleared' => true,
                'scoring_version' => 1, 'engine_version' => 'test', 'played_at' => $at, 'replay' => 'inputs',
            ]);
        }

        return medal_song($at, ['id' => $id, 'chart_sha256' => $chart->sha256]);
    };
    $titles = fn () => array_map('intval', \App\Models\PlayerCosmetic::query()->where('baid', $player->baid)
        ->where('game_version', 'waddamburo')->value('unlocked_titles') ?? []);
    $upload = fn (array $songs) => $this->withToken($token)->postJson('/api/wdb/medals/songs', ['songs' => $songs])->assertOk();

    // A credit with a failed song: only the first play's title (106).
    $upload([$song(true, '2026-11-20T10:00:00+01:00'), $song(false, '2026-11-20T10:05:00+01:00'), $song(true, '2026-11-20T10:10:00+01:00')]);
    expect($titles())->toBe([106]);

    // Every song cleared (and full combo): clear all (131), all J-POP (144), all FOOD (181), all alphabet titles (151).
    $upload([$song(true, '2026-11-20T11:00:00+01:00'), $song(true, '2026-11-20T11:05:00+01:00'), $song(true, '2026-11-20T11:10:00+01:00')]);
    expect($titles())->toContain(106, 131, 144, 181, 151)->not->toContain(146);
})->skip(fn () => ! file_exists(config('taiko_green.data_path').'/green/green_title_conditions.json'), 'needs the Green title conditions data file');

it('checks the condition titles that ask for an option, the drum or the timer', function (): void {
    [$player, $token] = medal_fixture();
    // セイクリッド ルイン (struin, uniqueId 400): 白の覚醒 (297) stops the timer on another repdigit, 黒の覚醒 (298) on
    // 66/88/99 with あべこべ (option bit 64).
    $chart = \App\Models\WdbChart::query()->create(['sha256' => str_repeat('d', 64), 'notes' => 'x', 'title' => 'Sacred Ruin', 'course' => 3, 'source' => 'Stock']);
    $songId = DB::table('wdb_songs')->insertGetId(['family' => 'Namco', 'song_key' => 'struin', 'title' => 'Sacred Ruin', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('wdb_chart_song')->insert(['wdb_chart_id' => $chart->id, 'wdb_song_id' => $songId, 'source' => 'Stock', 'release' => '']);
    $song = function (string $at, int $options, ?int $seconds) use ($player, $chart): array {
        $id = (string) Str::uuid();
        \App\Models\WdbPlay::query()->create([
            'id' => $id, 'baid' => $player->baid, 'wdb_chart_id' => $chart->id, 'mode' => 'normal', 'course' => 3, 'score' => 1,
            'great' => 100, 'good' => 0, 'miss' => 0, 'max_combo' => 100, 'rolls' => 0, 'gauge' => 50, 'cleared' => true,
            'scoring_version' => 1, 'engine_version' => 'test', 'played_at' => $at, 'replay' => 'inputs', 'options' => $options,
        ]);

        return medal_song($at, ['id' => $id, 'chart_sha256' => $chart->sha256, 'side' => 0, 'select_seconds' => $seconds]);
    };
    $titles = fn () => array_map('intval', \App\Models\PlayerCosmetic::query()->where('baid', $player->baid)
        ->where('game_version', 'waddamburo')->value('unlocked_titles') ?? []);
    $upload = fn (array $songs) => $this->withToken($token)->postJson('/api/wdb/medals/songs', ['songs' => $songs])->assertOk();

    // 88 without あべこべ: neither.
    $upload([$song('2026-11-20T10:00:00+01:00', 0, 88), $song('2026-11-20T10:05:00+01:00', 0, null), $song('2026-11-20T10:10:00+01:00', 0, 50)]);
    expect($titles())->not->toContain(297)->not->toContain(298);

    $upload([$song('2026-11-20T11:00:00+01:00', 64, 88), $song('2026-11-20T11:05:00+01:00', 0, 77), $song('2026-11-20T11:10:00+01:00', 0, 50)]);
    expect($titles())->toContain(297, 298);
})->skip(fn () => ! file_exists(config('taiko_green.data_path').'/green/green_title_conditions.json'), 'needs the Green title conditions data file');
