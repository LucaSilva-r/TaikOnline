<?php

use App\Models\GameCard;
use App\Models\Player;
use App\Models\User;
use App\Models\WdbChart;
use App\Models\WdbPlay;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set('taiko_green.zucchini_api_token_hashes', [hash('sha256', 'official-token')]);
});

function wdb_player(string $accessCode = '30800000000000000001', string $password = 'password'): Player
{
    $user = User::factory()->create(['username' => 'don'.Str::lower(Str::random(6)), 'password' => $password]);
    $player = Player::query()->create(['user_id' => $user->id, 'mydon_name' => 'どんちゃん']);
    GameCard::query()->create(['access_code' => $accessCode, 'baid' => $player->baid]);

    return $player->fresh('user');
}

/**
 * @return array<string, mixed>
 */
function wdb_play(string $sha256, array $overrides = []): array
{
    return [
        'id' => (string) Str::uuid(),
        'chart_sha256' => $sha256,
        'mode' => 'normal',
        'course' => 3,
        'score' => 1000000,
        'great' => 500,
        'good' => 10,
        'miss' => 0,
        'max_combo' => 510,
        'rolls' => 20,
        'gauge' => 50,
        'cleared' => true,
        'scoring_version' => 1,
        'engine_version' => 'test',
        'played_at' => '2026-09-25T20:00:00Z',
        'replay' => base64_encode(gzencode('inputs')),
        ...$overrides,
    ];
}

function wdb_token(Player $player): string
{
    return $player->user->createToken('test', ['wdb'])->plainTextToken;
}

it('logs in with username and password and returns a wdb token', function (): void {
    $player = wdb_player();

    $response = $this->postJson('/api/wdb/login', [
        'login' => $player->user->username, 'password' => 'password', 'device' => 'home pc',
    ])->assertOk()->assertJson(['baid' => $player->baid, 'name' => 'どんちゃん']);

    $this->withToken($response->json('token'))->getJson('/api/wdb/me')
        ->assertOk()->assertJson(['baid' => $player->baid]);
});

it('rejects wrong passwords and asks two-factor accounts for a code', function (): void {
    $player = wdb_player();
    $this->postJson('/api/wdb/login', ['login' => $player->user->email, 'password' => 'nope', 'device' => 'pc'])
        ->assertUnprocessable();

    $player->user->forceFill([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'),
        'two_factor_confirmed_at' => now(),
    ])->save();
    $this->postJson('/api/wdb/login', ['login' => $player->user->email, 'password' => 'password', 'device' => 'pc'])
        ->assertUnprocessable()->assertJson(['two_factor' => true]);
    $this->postJson('/api/wdb/login', [
        'login' => $player->user->email, 'password' => 'password', 'device' => 'pc', 'code' => '000000',
    ])->assertUnprocessable()->assertJsonValidationErrors('code');
});

it('rejects requests without a cabinet or wdb token', function (): void {
    $player = wdb_player();
    $this->postJson('/api/wdb/plays', ['plays' => [wdb_play(str_repeat('a', 64))]])->assertUnauthorized();
    $this->withToken($player->user->createToken('other', ['something'])->plainTextToken)
        ->getJson('/api/wdb/me')->assertUnauthorized();
});

it('stores home plays idempotently under the token owner and asks for unknown charts', function (): void {
    $player = wdb_player();
    $other = wdb_player('30800000000000000002');
    $play = wdb_play(str_repeat('a', 64));

    $this->withToken(wdb_token($player))->postJson('/api/wdb/plays', ['plays' => [$play, $play]])
        ->assertOk()
        ->assertJson(['missing_charts' => [str_repeat('a', 64)]]);
    $this->withToken(wdb_token($player))
        ->postJson('/api/wdb/plays', ['plays' => [wdb_play(str_repeat('a', 64), ['baid' => $other->baid])]])
        ->assertOk()->assertJson(['accepted' => []]);

    expect(WdbPlay::query()->count())->toBe(1)
        ->and(WdbPlay::query()->firstOrFail()->baid)->toBe($player->baid)
        ->and(gzdecode(WdbPlay::query()->firstOrFail()->replay))->toBe('inputs');
});

it('lets a cabinet resolve a paired card and upload plays for it', function (): void {
    $player = wdb_player();

    $player->forceFill(['color_face' => 25, 'color_body' => 2, 'color_limb' => 26])->save();
    $player->cosmetics()->create(['game_version' => 'green', 'costume_1' => 32]);

    $this->withToken('official-token')->postJson('/api/wdb/cards', ['access_code' => '30800000000000000001'])
        ->assertOk()->assertJson(['baid' => $player->baid, 'look' => [
            'costume' => [32, 0, 0, 0, 0], 'face' => '#b3dbff', 'body' => '#dd1400', 'limb' => '#b9b9b9',
        ]]);
    $this->withToken('official-token')->postJson('/api/wdb/cards', ['access_code' => '30800000000000000009'])
        ->assertNotFound();
    $this->withToken('official-token')
        ->postJson('/api/wdb/plays', ['plays' => [wdb_play(str_repeat('b', 64), ['baid' => $player->baid])]])
        ->assertOk();

    expect(WdbPlay::query()->where('baid', $player->baid)->count())->toBe(1);
});

it('imports chart notes only when they match the hash', function (): void {
    $player = wdb_player();
    $notes = 'WDBC canonical notes';
    $sha = hash('sha256', $notes);
    $token = wdb_token($player);

    $this->withToken($token)->putJson("/api/wdb/charts/{$sha}", ['notes' => base64_encode(gzencode('forged'))])
        ->assertUnprocessable();
    $this->withToken($token)->putJson("/api/wdb/charts/{$sha}", [
        'notes' => base64_encode(gzencode($notes)), 'title' => 'Song', 'course' => 3, 'level' => 8,
    ])->assertNoContent();
    $this->withToken($token)->postJson('/api/wdb/plays', ['plays' => [wdb_play($sha)]])
        ->assertOk()->assertJson(['missing_charts' => []]);

    $chart = WdbChart::query()->where('sha256', $sha)->firstOrFail();
    expect(gzdecode($chart->notes))->toBe($notes)
        ->and($chart->title)->toBe('Song')
        ->and($chart->ranked_at)->toBeNull();
});

it('revokes the token on logout', function (): void {
    $player = wdb_player();
    $token = wdb_token($player);

    $this->withToken($token)->deleteJson('/api/wdb/login')->assertNoContent();

    expect($player->user->tokens()->count())->toBe(0);
});
