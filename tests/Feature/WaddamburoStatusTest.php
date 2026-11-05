<?php

use App\Enums\PresenceStatus;
use App\Enums\SanctionType;
use App\Events\WdbPlayerStatusChanged;
use App\Models\Player;
use App\Models\Sanction;
use App\Models\User;
use App\Models\WdbCabinet;
use App\Models\WdbChart;
use App\Services\WaddamburoStatus;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

function wdb_status_player(): User
{
    $user = User::factory()->create();
    Player::query()->create(['user_id' => $user->id]);

    return $user;
}

function wdb_status_chart(array $attributes = []): WdbChart
{
    return WdbChart::query()->create(['sha256' => str_repeat('a', 64), 'notes' => 'x', 'title' => '千本桜', 'course' => 3, 'ranked_at' => now(), ...$attributes]);
}

beforeEach(function (): void {
    Event::fake([WdbPlayerStatusChanged::class]);
    config(['legal.version' => null]);
});

it('shows and pushes what a home player is doing', function (): void {
    $user = wdb_status_player();
    $chart = wdb_status_chart();

    $this->withToken($user->createToken('test', ['wdb'])->plainTextToken)
        ->putJson('/api/wdb/status', ['state' => 'playing', 'chart_sha256' => $chart->sha256])->assertNoContent();

    $expected = ['state' => 'playing', 'song' => ['title' => '千本桜', 'course' => 3]];
    expect(app(WaddamburoStatus::class)->of($user))->toBe($expected);
    Event::assertDispatched(WdbPlayerStatusChanged::class, fn (WdbPlayerStatusChanged $event): bool => $event->broadcastOn()->name === "wdb.status.{$user->id}"
        && $event->broadcastWith() === $expected);
});

it('never names a custom chart or one the server does not know', function (string $sha256): void {
    $user = wdb_status_player();
    wdb_status_chart(['sha256' => str_repeat('b', 64), 'title' => 'Private community chart', 'ranked_at' => null]);

    $this->withToken($user->createToken('test', ['wdb'])->plainTextToken)
        ->putJson('/api/wdb/status', ['state' => 'playing', 'chart_sha256' => $sha256])->assertNoContent();

    expect(app(WaddamburoStatus::class)->of($user))->toBe(['state' => 'playing', 'song' => null]);
})->with(['custom' => [str_repeat('b', 64)], 'unknown' => [str_repeat('c', 64)]]);

it('goes offline when the game says so or stops reporting', function (): void {
    $user = wdb_status_player();
    $token = $user->createToken('test', ['wdb'])->plainTextToken;

    $this->withToken($token)->putJson('/api/wdb/status', ['state' => 'afk'])->assertNoContent();
    expect(app(WaddamburoStatus::class)->of($user)['state'])->toBe('afk');
    $this->travel(WaddamburoStatus::TTL_SECONDS + 1)->seconds();
    expect(app(WaddamburoStatus::class)->of($user))->toBeNull();

    $this->travelBack();
    $this->withToken($token)->putJson('/api/wdb/status', ['state' => 'online'])->assertNoContent();
    $this->withToken($token)->deleteJson('/api/wdb/status')->assertNoContent();
    expect(app(WaddamburoStatus::class)->of($user))->toBeNull();
    Event::assertDispatched(WdbPlayerStatusChanged::class, fn (WdbPlayerStatusChanged $event): bool => $event->status['state'] === 'offline');
});

it('names an osu! beatmap, which is public anyway', function (): void {
    $user = wdb_status_player();
    $chart = wdb_status_chart(['title' => 'sh0ut', 'ranked_at' => null, 'osu_beatmapset_id' => 728008]);

    $this->withToken($user->createToken('test', ['wdb'])->plainTextToken)
        ->putJson('/api/wdb/status', ['state' => 'playing', 'chart_sha256' => $chart->sha256])->assertNoContent();

    expect(app(WaddamburoStatus::class)->of($user)['song']['title'])->toBe('sh0ut');
});

it('hides a player from everyone but the server while they are invisible', function (): void {
    $user = wdb_status_player();
    $token = $user->createToken('test', ['wdb'])->plainTextToken;
    $this->withToken($token)->putJson('/api/wdb/status', ['state' => 'online'])->assertNoContent();

    $this->actingAs($user)->patch(route('online-status.update', ['taikoVersion' => 'waddamburo']), ['presence_status' => 'invisible'])->assertRedirect();

    expect($user->fresh()->presence_status)->toBe(PresenceStatus::Invisible)
        ->and(app(WaddamburoStatus::class)->of($user->fresh()))->toBeNull();
    Event::assertDispatched(WdbPlayerStatusChanged::class, fn (WdbPlayerStatusChanged $event): bool => $event->status['state'] === 'offline');
    $this->get(route('board.show', ['taikoVersion' => 'waddamburo', 'user' => $user->id]))
        ->assertInertia(fn (Assert $page) => $page->where('onlineStatus', null));
    app('auth')->forgetGuards();

    Event::fake([WdbPlayerStatusChanged::class]);
    $this->withToken($token)->putJson('/api/wdb/status', ['state' => 'afk'])->assertNoContent();
    Event::assertNotDispatched(WdbPlayerStatusChanged::class);
});

it('shows a player who stops being invisible at once, with what the game last reported', function (): void {
    $user = wdb_status_player();
    $user->update(['presence_status' => PresenceStatus::Invisible]);
    $this->withToken($user->createToken('test', ['wdb'])->plainTextToken)
        ->putJson('/api/wdb/status', ['state' => 'afk'])->assertNoContent();
    app('auth')->forgetGuards();

    $this->actingAs($user->fresh())->patch(route('online-status.update', ['taikoVersion' => 'waddamburo']), ['presence_status' => 'online'])->assertRedirect();

    expect(app(WaddamburoStatus::class)->of($user->fresh())['state'])->toBe('afk');
    Event::assertDispatched(WdbPlayerStatusChanged::class, fn (WdbPlayerStatusChanged $event): bool => $event->status['state'] === 'afk');
});

it('refuses a status that does not exist', function (): void {
    $user = wdb_status_player();

    $this->actingAs($user)->patch(route('online-status.update', ['taikoVersion' => 'waddamburo']), ['presence_status' => 'hiding'])
        ->assertSessionHasErrors('presence_status');
    expect($user->fresh()->presence_status)->toBe(PresenceStatus::Online);
});

it('only lets a logged-in user change their online status', function (): void {
    $this->patch(route('online-status.update', ['taikoVersion' => 'waddamburo']), ['presence_status' => 'invisible'])->assertRedirect(route('login'));
});

it('shows nothing for a banned player', function (): void {
    $user = wdb_status_player();
    Sanction::query()->create(['baid' => $user->player->baid, 'type' => SanctionType::Ban, 'reason' => 'macro']);

    $this->withToken($user->createToken('test', ['wdb'])->plainTextToken)
        ->putJson('/api/wdb/status', ['state' => 'online'])->assertNoContent();

    expect(app(WaddamburoStatus::class)->of($user))->toBeNull();
    Event::assertNotDispatched(WdbPlayerStatusChanged::class);
});

it('shows nothing for a player who has not accepted the current Rules', function (): void {
    config(['legal.version' => '2099-01-01', 'legal.path' => resource_path('legal')]);
    $user = wdb_status_player();
    $user->update(['rules_accepted_version' => 'older']);

    $this->withToken($user->createToken('test', ['wdb'])->plainTextToken)
        ->putJson('/api/wdb/status', ['state' => 'online'])->assertNoContent();

    expect(app(WaddamburoStatus::class)->of($user))->toBeNull();
});

it('refuses a bad state, a cabinet and a missing token', function (): void {
    $user = wdb_status_player();
    $this->withToken($user->createToken('test', ['wdb'])->plainTextToken)
        ->putJson('/api/wdb/status', ['state' => 'invisible'])->assertUnprocessable()->assertJsonValidationErrors('state');
    app('auth')->forgetGuards();

    [, $cabinetToken] = WdbCabinet::issue('Cab', null);
    $this->withToken($cabinetToken)->putJson('/api/wdb/status', ['state' => 'online'])->assertNotFound();
    app('auth')->forgetGuards();

    $this->withoutToken()->putJson('/api/wdb/status', ['state' => 'online'])->assertUnauthorized();
});

it('gives the board the status, and still renders when the store is down', function (): void {
    $user = wdb_status_player();
    app(WaddamburoStatus::class)->report($user, 'afk', null);

    $board = route('board.show', ['taikoVersion' => 'waddamburo', 'user' => $user->id]);

    $this->actingAs($user)->get($board)->assertOk()->assertInertia(fn (Assert $page) => $page->where('onlineStatus.state', 'afk'));

    config(['cache.stores.status' => ['driver' => 'redis', 'connection' => 'does-not-exist']]);
    app('cache')->forgetDriver('status');
    $this->get($board)->assertOk()->assertInertia(fn (Assert $page) => $page->where('onlineStatus', null));
});

it('names the other player of the online room, unless they are invisible', function (): void {
    $host = wdb_status_player();
    $guest = wdb_status_player();
    $guest->update(['name' => 'Katsu']);
    $rooms = app(\App\Services\WaddamburoRooms::class);
    $code = $rooms->create($host);
    $rooms->join($code, $guest);
    $token = $host->createToken('test', ['wdb'])->plainTextToken;

    $this->withToken($token)->putJson('/api/wdb/status', ['state' => 'online', 'room' => $code])->assertNoContent();
    expect(app(WaddamburoStatus::class)->of($host)['opponent'])->toBe(['name' => 'Katsu']);

    // A room the player isn't in names nobody.
    app('auth')->forgetGuards();
    $this->withToken(wdb_status_player()->createToken('test', ['wdb'])->plainTextToken)
        ->putJson('/api/wdb/status', ['state' => 'online', 'room' => $code])->assertNoContent();

    $guest->update(['presence_status' => PresenceStatus::Invisible]);
    app('auth')->forgetGuards();
    $this->withToken($token)->putJson('/api/wdb/status', ['state' => 'online', 'room' => $code])->assertNoContent();
    expect(app(WaddamburoStatus::class)->of($host))->not->toHaveKey('opponent');
});

it('counts the players in Waddamburo for the home page', function (): void {
    $first = wdb_status_player();
    $second = wdb_status_player();
    $this->withToken($first->createToken('test', ['wdb'])->plainTextToken)->putJson('/api/wdb/status', ['state' => 'online'])->assertNoContent();
    $secondToken = $second->createToken('test', ['wdb'])->plainTextToken;
    app('auth')->forgetGuards();
    $this->withToken($secondToken)->putJson('/api/wdb/status', ['state' => 'afk'])->assertNoContent();
    expect(app(WaddamburoStatus::class)->online())->toBe(2);

    $this->withToken($secondToken)->deleteJson('/api/wdb/status')->assertNoContent();
    expect(app(WaddamburoStatus::class)->online())->toBe(1);

    $this->travel(WaddamburoStatus::TTL_SECONDS + 1)->seconds();
    expect(app(WaddamburoStatus::class)->online())->toBe(0);
});
