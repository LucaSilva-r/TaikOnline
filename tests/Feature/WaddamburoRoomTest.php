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
        ->assertOk()->assertExactJson(['code' => $code, 'channel' => "presence-wdb.room.{$code}", 'name' => 'Don', 'is_public' => true, 'has_password' => false]);
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

it('matches quick play with whoever is waiting, and only them', function (): void {
    $first = wdb_room_player('First');
    $second = wdb_room_player('Second');
    $third = wdb_room_player('Third');

    $room = $this->withToken(wdb_room_token($first))->postJson('/api/wdb/rooms/quick-play')->assertOk()->json();
    expect($room['channel'])->toBe("presence-wdb.room.{$room['code']}");
    // Asking again while waiting is the same place, not a second one.
    app('auth')->forgetGuards();
    expect($this->withToken(wdb_room_token($first))->postJson('/api/wdb/rooms/quick-play')->json('code'))->toBe($room['code']);

    app('auth')->forgetGuards();
    expect($this->withToken(wdb_room_token($second))->postJson('/api/wdb/rooms/quick-play')->json('code'))->toBe($room['code']);

    // Those two are matched: the next player starts waiting in a room of their own.
    app('auth')->forgetGuards();
    expect($this->withToken(wdb_room_token($third))->postJson('/api/wdb/rooms/quick-play')->json('code'))->not->toBe($room['code']);
});

it('forgets a waiting player who stops renewing or leaves, and never matches them afterwards', function (): void {
    $this->freezeTime();
    $gone = wdb_room_player('Gone');
    $code = $this->withToken(wdb_room_token($gone))->postJson('/api/wdb/rooms/quick-play')->json('code');

    $this->travel(20)->seconds();
    app('auth')->forgetGuards();
    $this->withToken(wdb_room_token($gone))->postJson("/api/wdb/rooms/{$code}/keep-alive")->assertNoContent();
    $this->travel(20)->seconds();
    app('auth')->forgetGuards();
    expect($this->withToken(wdb_room_token(wdb_room_player('Next')))->postJson('/api/wdb/rooms/quick-play')->json('code'))->toBe($code);

    $late = wdb_room_player('Late');
    app('auth')->forgetGuards();
    $lateCode = $this->withToken(wdb_room_token($late))->postJson('/api/wdb/rooms/quick-play')->json('code');
    $this->travel(31)->seconds();
    app('auth')->forgetGuards();
    expect($this->withToken(wdb_room_token(wdb_room_player('After')))->postJson('/api/wdb/rooms/quick-play')->json('code'))->not->toBe($lateCode);

    $leaver = wdb_room_player('Leaver');
    app('auth')->forgetGuards();
    $leaverToken = wdb_room_token($leaver);
    $this->travel(31)->seconds();
    $leaverCode = $this->withToken($leaverToken)->postJson('/api/wdb/rooms/quick-play')->json('code');
    app('auth')->forgetGuards();
    $this->withToken($leaverToken)->deleteJson("/api/wdb/rooms/{$leaverCode}")->assertNoContent();
    app('auth')->forgetGuards();
    expect($this->withToken(wdb_room_token(wdb_room_player('Other')))->postJson('/api/wdb/rooms/quick-play')->json('code'))->not->toBe($leaverCode);
});

it('lists public rooms with a free seat, and never their passwords or unlisted ones', function (): void {
    $viewer = wdb_room_player('Viewer');
    $publicHost = wdb_room_player('Pub');
    $public = $this->withToken(wdb_room_token($publicHost))->postJson('/api/wdb/rooms', ['name' => 'Oni only', 'password' => 'hunter2'])->assertCreated()->json('code');
    app('auth')->forgetGuards();
    $this->withToken(wdb_room_token(wdb_room_player('Hidden')))->postJson('/api/wdb/rooms', ['is_public' => false])->assertCreated();
    app('auth')->forgetGuards();
    $full = $this->withToken(wdb_room_token(wdb_room_player('Full')))->postJson('/api/wdb/rooms')->json('code');
    app('auth')->forgetGuards();
    $this->withToken(wdb_room_token(wdb_room_player('Guest')))->postJson("/api/wdb/rooms/{$full}/join")->assertOk();
    app('auth')->forgetGuards();

    $response = $this->withToken(wdb_room_token($viewer))->getJson('/api/wdb/rooms')->assertOk();

    expect($response->json('rooms'))->toBe([['code' => $public, 'name' => 'Oni only', 'host' => 'Pub', 'has_password' => true]])
        ->and($response->getContent())->not->toContain('hunter2');
});

it('drops a room from the list when its game stops renewing it', function (): void {
    $this->freezeTime();
    $host = wdb_room_player('Host');
    $token = wdb_room_token($host);
    $code = $this->withToken($token)->postJson('/api/wdb/rooms')->json('code');

    $this->travel(40)->seconds();
    app('auth')->forgetGuards();
    $this->withToken($token)->postJson("/api/wdb/rooms/{$code}/keep-alive")->assertNoContent();
    $this->travel(40)->seconds();
    app('auth')->forgetGuards();
    expect($this->withToken($token)->getJson('/api/wdb/rooms')->json('rooms'))->toHaveCount(1);

    $this->travel(46)->seconds();
    app('auth')->forgetGuards();
    expect($this->withToken($token)->getJson('/api/wdb/rooms')->json('rooms'))->toBe([]);
    app('auth')->forgetGuards();
    $this->withToken($token)->postJson("/api/wdb/rooms/{$code}/keep-alive")->assertNotFound();
});

it('asks for a room\'s password, except from its own members', function (): void {
    $host = wdb_room_player('Host');
    $code = $this->withToken(wdb_room_token($host))->postJson('/api/wdb/rooms', ['password' => 'hunter2'])->json('code');
    $guest = wdb_room_player('Guest');
    $token = wdb_room_token($guest);

    app('auth')->forgetGuards();
    $this->withToken($token)->postJson("/api/wdb/rooms/{$code}/join")->assertForbidden()->assertJsonPath('wrong_password', true);
    app('auth')->forgetGuards();
    $this->withToken($token)->postJson("/api/wdb/rooms/{$code}/join", ['password' => 'wrong'])->assertForbidden();
    app('auth')->forgetGuards();
    $this->withToken($token)->postJson("/api/wdb/rooms/{$code}/join", ['password' => 'hunter2'])->assertOk()->assertJsonPath('has_password', true);
    app('auth')->forgetGuards();
    $this->withToken($token)->postJson("/api/wdb/rooms/{$code}/join")->assertOk();
});

it('lets only the host change a room', function (): void {
    $host = wdb_room_player('Host');
    $hostToken = wdb_room_token($host);
    $code = $this->withToken($hostToken)->postJson('/api/wdb/rooms')->json('code');
    app('auth')->forgetGuards();
    $guest = wdb_room_player('Guest');
    $guestToken = wdb_room_token($guest);

    $this->withToken($guestToken)->patchJson("/api/wdb/rooms/{$code}", ['name' => 'Mine now'])->assertNotFound();
    app('auth')->forgetGuards();
    $this->withToken($hostToken)->patchJson("/api/wdb/rooms/{$code}", ['name' => "  Friends\u{0007} only ", 'is_public' => false, 'password' => 'x'])->assertOk()
        ->assertJsonPath('name', 'Friends only')->assertJsonPath('is_public', false)->assertJsonPath('has_password', true);
    app('auth')->forgetGuards();
    expect($this->withToken($guestToken)->getJson('/api/wdb/rooms')->json('rooms'))->toBe([]);
    app('auth')->forgetGuards();
    $this->withToken($hostToken)->patchJson("/api/wdb/rooms/{$code}", ['password' => ''])->assertOk()->assertJsonPath('has_password', false);
    app('auth')->forgetGuards();
    $this->withToken($guestToken)->postJson("/api/wdb/rooms/{$code}/join")->assertOk();
});

it('names a room after its host when unnamed or when the host is muted', function (): void {
    $this->withToken(wdb_room_token(wdb_room_player('Plain')))->postJson('/api/wdb/rooms', ['name' => '   '])->assertCreated()->assertJsonPath('name', 'Plain');

    $muted = wdb_room_player('Muted');
    Sanction::query()->create(['baid' => $muted->player->baid, 'type' => SanctionType::Mute, 'reason' => 'spam']);
    app('auth')->forgetGuards();
    $this->withToken(wdb_room_token($muted))->postJson('/api/wdb/rooms', ['name' => 'Rude words'])->assertCreated()->assertJsonPath('name', 'Muted');
});

it('keeps banned players and cabinets out, and needs a token', function (): void {
    $code = wdb_room_code(wdb_room_player());
    $banned = wdb_room_player('Banned');
    Sanction::query()->create(['baid' => $banned->player->baid, 'type' => SanctionType::Ban, 'reason' => 'macro']);

    $this->withToken(wdb_room_token($banned))->postJson("/api/wdb/rooms/{$code}/join")->assertForbidden();
    app('auth')->forgetGuards();
    $this->withToken(wdb_room_token($banned))->postJson('/api/wdb/rooms')->assertForbidden();
    app('auth')->forgetGuards();
    $this->withToken(wdb_room_token($banned))->postJson('/api/wdb/rooms/quick-play')->assertForbidden();
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
