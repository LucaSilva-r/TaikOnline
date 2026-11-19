<?php

use App\Models\Player;
use App\Models\User;
use App\Models\WdbCabinet;
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
