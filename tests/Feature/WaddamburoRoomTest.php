<?php

use App\Enums\SanctionType;
use App\Models\Player;
use App\Models\Sanction;
use App\Models\User;
use App\Models\WdbCabinet;

beforeEach(function (): void {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => '1',
        'services.turn' => ['host' => null, 'port' => 3478, 'secret' => null],
    ]);
    // Channels register on the broadcaster booted with the app (null under tests).
    require base_path('routes/channels.php');
});

function wdb_room_player(string $name = 'Don'): User
{
    $user = User::factory()->create(['name' => $name]);
    Player::query()->create(['user_id' => $user->id]);

    return $user;
}

function wdb_room_token(User $user): string
{
    app('auth')->forgetGuards();

    return $user->createToken('test', ['wdb'])->plainTextToken;
}

function wdb_room_code(User $host): string
{
    return test()->withToken(wdb_room_token($host))->postJson('/api/wdb/rooms')->assertCreated()->json('code');
}

function wdb_room_auth(string $code): array
{
    return ['socket_id' => '1234.5678', 'channel_name' => "presence-wdb.room.{$code}"];
}

it('opens a room with a code the guest can join, in any case', function (): void {
    $host = wdb_room_player();
    $code = wdb_room_code($host);

    expect($code)->toMatch('/\A[A-HJ-NP-Z2-9]{6}\z/');
    $this->withToken(wdb_room_token(wdb_room_player('Katsu')))->postJson('/api/wdb/rooms/'.strtolower($code).'/join')
        ->assertOk()->assertExactJson(['code' => $code, 'channel' => "presence-wdb.room.{$code}"]);
});

it('has one guest seat, which they can come back to and which frees when they leave', function (): void {
    $code = wdb_room_code(wdb_room_player());
    $guest = wdb_room_player('Guest');
    $guestToken = wdb_room_token($guest);
    $third = wdb_room_player('Third');

    $this->withToken($guestToken)->postJson("/api/wdb/rooms/{$code}/join")->assertOk();
    app('auth')->forgetGuards();
    $this->withToken(wdb_room_token($third))->postJson("/api/wdb/rooms/{$code}/join")->assertNotFound();
    app('auth')->forgetGuards();
    $this->withToken($guestToken)->postJson("/api/wdb/rooms/{$code}/join")->assertOk();
    app('auth')->forgetGuards();
    $this->withToken($guestToken)->deleteJson("/api/wdb/rooms/{$code}")->assertNoContent();
    app('auth')->forgetGuards();
    $this->withToken(wdb_room_token($third))->postJson("/api/wdb/rooms/{$code}/join")->assertOk();
});

it('closes the room when its host leaves', function (): void {
    $host = wdb_room_player();
    $code = wdb_room_code($host);

    app('auth')->forgetGuards();
    $this->withToken(wdb_room_token($host))->deleteJson("/api/wdb/rooms/{$code}")->assertNoContent();

    $this->withToken(wdb_room_token(wdb_room_player('Guest')))->postJson("/api/wdb/rooms/{$code}/join")->assertNotFound();
});

it('keeps banned players and cabinets out, and needs a token', function (): void {
    $code = wdb_room_code(wdb_room_player());
    $banned = wdb_room_player('Banned');
    Sanction::query()->create(['baid' => $banned->player->baid, 'type' => SanctionType::Ban, 'reason' => 'macro']);

    $this->withToken(wdb_room_token($banned))->postJson("/api/wdb/rooms/{$code}/join")->assertForbidden();
    app('auth')->forgetGuards();
    $this->withToken(wdb_room_token($banned))->postJson('/api/wdb/rooms')->assertForbidden();
    app('auth')->forgetGuards();

    [, $cabinetToken] = WdbCabinet::issue('Cab', null);
    $this->withToken($cabinetToken)->postJson('/api/wdb/rooms')->assertNotFound();
    app('auth')->forgetGuards();

    $this->withoutToken()->postJson('/api/wdb/rooms')->assertUnauthorized();
});

it('signs the room channel for its members only, with who they are', function (): void {
    $host = wdb_room_player('Host');
    $code = wdb_room_code($host);

    $response = $this->withToken(wdb_room_token($host))->postJson('/api/wdb/broadcasting/auth', wdb_room_auth($code))->assertOk();
    expect(json_decode($response->json('channel_data'), true)['user_info'])
        ->toBe(['id' => $host->id, 'name' => 'Host', 'baid' => $host->player->baid]);

    $this->withToken(wdb_room_token(wdb_room_player('Stranger')))->postJson('/api/wdb/broadcasting/auth', wdb_room_auth($code))->assertForbidden();
});

it('refuses the room channel to a website session, even a member\'s', function (): void {
    $host = wdb_room_player();
    $code = wdb_room_code($host);
    app('auth')->forgetGuards();

    $this->actingAs($host)->postJson('/broadcasting/auth', wdb_room_auth($code))->assertForbidden();
});

it('gives a room\'s members a public STUN server without a coturn, and nobody else anything', function (): void {
    $host = wdb_room_player();
    $code = wdb_room_code($host);

    $this->withToken(wdb_room_token($host))->getJson("/api/wdb/rooms/{$code}/ice-servers")->assertOk()
        ->assertExactJson(['ice_servers' => [['urls' => ['stun:stun.cloudflare.com:3478']]]]);

    $this->withToken(wdb_room_token(wdb_room_player('Stranger')))->getJson("/api/wdb/rooms/{$code}/ice-servers")->assertNotFound();
});

it('signs TURN credentials coturn can check, valid as long as a room lasts and without giving away the secret', function (): void {
    config(['services.turn' => ['host' => 'turn.taikonline.test', 'port' => 3478, 'secret' => 'shared-secret']]);
    $this->freezeTime();
    $host = wdb_room_player();
    $code = wdb_room_code($host);

    $response = $this->withToken(wdb_room_token($host))->getJson("/api/wdb/rooms/{$code}/ice-servers")->assertOk();

    $username = (now()->getTimestamp() + 6 * 3600).":{$host->id}";
    expect($response->json('ice_servers'))->toBe([
        ['urls' => ['stun:turn.taikonline.test:3478']],
        ['urls' => ['turn:turn.taikonline.test:3478?transport=udp'], 'username' => $username, 'credential' => base64_encode(hash_hmac('sha1', $username, 'shared-secret', true))],
    ])->and($response->getContent())->not->toContain('shared-secret');
});
