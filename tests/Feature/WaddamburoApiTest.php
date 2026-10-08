<?php

use App\Models\GameCard;
use App\Models\Player;
use App\Models\PlayerCosmetic;
use App\Models\User;
use App\Models\WdbCabinet;
use App\Models\WdbChart;
use App\Models\WdbPlay;
use App\Services\CabinetPairingService;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    // A Waddamburo cabinet an admin let in (the token's hash, as WdbCabinet::issue keeps it).
    WdbCabinet::query()->create(['name' => 'Test cab', 'token_hash' => hash('sha256', 'official-token')]);
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
        ->assertOk()->assertJson(['baid' => $player->baid, 'account_name' => $player->user->name]);
});

it('sends the waddamburo loadout: title, plate and costume sets', function (): void {
    $player = wdb_player();
    PlayerCosmetic::create(['baid' => $player->baid, 'game_version' => 'green', 'title' => 'green only']);
    PlayerCosmetic::create([
        'baid' => $player->baid, 'game_version' => 'waddamburo', 'title' => 'ほんのきもち', 'titleplate_id' => 3,
        'costume_presets' => [['costume_1' => 32], ['costume_2' => 10, 'costume_3' => 2, 'costume_5' => 4]],
    ]);

    $this->withToken(wdb_token($player))->getJson('/api/wdb/me')
        ->assertOk()->assertJson(['title' => 'ほんのきもち', 'title_plate' => 3,
            'look' => ['presets' => [[32, 0, 0, 0], [0, 10, 2, 4], [0, 0, 0, 0]]]]);
});

it('saves the waddamburo scope\'s title to its own loadout, not green\'s', function (): void {
    $player = wdb_player();
    $this->actingAs($player->user)
        ->patch('/waddamburo/settings/donchan-title', ['title' => 'わだんぶろ', 'titleplate_id' => 1])
        ->assertRedirect();

    expect(PlayerCosmetic::where('baid', $player->baid)->pluck('title', 'game_version')->all())
        ->toBe(['waddamburo' => 'わだんぶろ']);
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
    $player->cosmetics()->create(['game_version' => 'waddamburo', 'costume_1' => 32]);

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
        'notes' => base64_encode(gzencode($notes)), 'title' => 'Song', 'source' => 'OsuLazer', 'course' => 3, 'level' => 8,
    ])->assertNoContent();
    $this->withToken($token)->postJson('/api/wdb/plays', ['plays' => [wdb_play($sha)]])
        ->assertOk()->assertJson(['missing_charts' => []]);

    $chart = WdbChart::query()->where('sha256', $sha)->firstOrFail();
    expect(gzdecode($chart->notes))->toBe($notes)
        ->and($chart->title)->toBe('Song')
        ->and($chart->ranked_at)->toBeNull();

    // A newer client re-sending the chart fills in the metadata it lacked, and changes nothing else.
    $this->withToken($token)->putJson("/api/wdb/charts/{$sha}", [
        'notes' => base64_encode(gzencode($notes)), 'title' => 'Other', 'title_en' => 'Song (English)',
        'difficulty' => 'Inner Oni', 'osu_beatmap_id' => 123, 'osu_beatmapset_id' => 45, 'song_key' => 'set:45', 'source' => 'OsuLazer',
    ])->assertNoContent();
    expect($chart->fresh())->title->toBe('Song')->title_en->toBe('Song (English)')->difficulty->toBe('Inner Oni')
        ->and($chart->fresh()->osuUrl())->toBe('https://osu.ppy.sh/beatmapsets/45#taiko/123')
        ->and($chart->songs()->pluck('song_key')->all())->toBe(['set:45']);
});

it('scores plays from their replays once the chart is known, keeping the rest off the boards', function (): void {
    config(['services.waddamburo.scorer' => PHP_BINARY]); // any executable: the process is faked
    $runs = [];
    Process::fake(function (PendingProcess $process) use (&$runs) {
        $runs[] = $process->command;
        $lines = collect(explode("\n", trim((string) $process->input)))->map(fn (string $line): array => json_decode($line, true));

        return Process::result($lines->map(fn (array $play): string => json_encode($play['replay'] === base64_encode('broken')
            ? ['id' => $play['id'], 'error' => 'Unknown replay format.']
            : ['id' => $play['id'], 'course' => 3, 'score' => 123450, 'great' => 7, 'good' => 2, 'miss' => 1, 'max_combo' => 6,
                'rolls' => 4, 'gauge' => 30, 'cleared' => false, 'scoring_version' => 2]))->implode("\n"));
    });
    $player = wdb_player();
    $token = wdb_token($player);
    $notes = 'WDBC canonical notes';
    $sha = hash('sha256', $notes);
    $early = wdb_play($sha);

    // The chart is not known yet: the play keeps the client's numbers and stays off the boards.
    $this->withToken($token)->postJson('/api/wdb/plays', ['plays' => [$early]])->assertJson(['missing_charts' => [$sha]]);
    expect($runs)->toBe([]);
    $this->withToken($token)->getJson("/api/wdb/charts/{$sha}/scores")->assertJsonCount(0, 'scores');

    // Its notes arrive: the play scores from its replay.
    $this->withToken($token)->putJson("/api/wdb/charts/{$sha}", ['notes' => base64_encode(gzencode($notes))])->assertNoContent();
    expect(WdbPlay::query()->findOrFail($early['id']))->score->toBe(123450)->great->toBe(7)->cleared->toBeFalse()
        ->scoring_version->toBe(2)->rescored_at->not->toBeNull()
        ->and($runs)->toBe([[PHP_BINARY, '--rescore']]);
    $this->withToken($token)->getJson("/api/wdb/charts/{$sha}/scores")->assertJsonPath('scores.0.score', 123450);

    // Later plays score on upload; one whose replay does not score stays off the boards.
    $later = wdb_play($sha, ['score' => 999999]);
    $broken = wdb_play($sha, ['score' => 999999, 'replay' => base64_encode('broken')]);
    $this->withToken($token)->postJson('/api/wdb/plays', ['plays' => [$later, $broken]])->assertJson(['missing_charts' => []]);
    expect(WdbPlay::query()->findOrFail($later['id'])->score)->toBe(123450)
        ->and(WdbPlay::query()->findOrFail($broken['id'])->rescored_at)->toBeNull();
    $this->withToken($token)->getJson("/api/wdb/charts/{$sha}/scores")->assertJsonPath('scores.0.score', 123450);

    // A rules change: the command rescores every play.
    $this->artisan('app:rescore-waddamburo-plays', ['--all' => true])->assertSuccessful();
    expect($runs)->toHaveCount(3);
});

it('revokes the token on logout', function (): void {
    $player = wdb_player();
    $token = wdb_token($player);

    $this->withToken($token)->deleteJson('/api/wdb/login')->assertNoContent();

    expect($player->user->tokens()->count())->toBe(0);
});

it('logs a device in once the player approves its code on the website', function (): void {
    $player = wdb_player();

    $start = $this->postJson('/api/wdb/device', ['device' => 'Waddamburo on den-pc'])
        ->assertOk()->assertJsonStructure(['device_code', 'user_code', 'expires_in', 'interval', 'verification_url']);
    $deviceCode = $start->json('device_code');
    $userCode = $start->json('user_code');

    $this->postJson('/api/wdb/device/token', ['device_code' => $deviceCode])->assertOk()->assertJson(['status' => 'pending']);

    $this->actingAs($player->user)->get("/green/link?code={$userCode}")
        ->assertInertia(fn (Assert $page) => $page->component('Link')->where('device', 'Waddamburo on den-pc'));
    $this->actingAs($player->user)->post('/green/link', ['code' => $userCode, 'approve' => true])->assertRedirect();

    $approved = $this->postJson('/api/wdb/device/token', ['device_code' => $deviceCode])
        ->assertOk()->assertJson(['status' => 'approved', 'baid' => $player->baid]);
    $this->app['auth']->forgetGuards();
    $this->withToken($approved->json('token'))->getJson('/api/wdb/me')->assertOk()->assertJson(['baid' => $player->baid]);
    expect($player->user->tokens()->first()->name)->toBe('Waddamburo on den-pc');

    // The token is issued once; the code cannot be reused.
    $this->postJson('/api/wdb/device/token', ['device_code' => $deviceCode])->assertJson(['status' => 'expired']);
    $this->actingAs($player->user)->post('/green/link', ['code' => $userCode, 'approve' => true])
        ->assertSessionHasErrors('code');
});

it('tells the device when the player denies it', function (): void {
    $player = wdb_player();
    $start = $this->postJson('/api/wdb/device', ['device' => 'Unknown PC'])->assertOk();

    $this->actingAs($player->user)->post('/green/link', ['code' => $start->json('user_code'), 'approve' => false])
        ->assertRedirect();

    $this->postJson('/api/wdb/device/token', ['device_code' => $start->json('device_code')])
        ->assertJson(['status' => 'denied']);
    expect($player->user->tokens()->count())->toBe(0);
});

it('gives a home PC a short-lived token for a friend who pairs with the six-digit code', function (): void {
    $owner = wdb_player();
    $friend = wdb_player('30800000000000000002');
    $token = wdb_token($owner);

    $active = $this->withToken($token)->postJson('/api/wdb/pairing', ['accepting' => true])
        ->assertOk()->assertJson(['status' => 'active']);
    expect(app(CabinetPairingService::class)->claim($active->json('code'), '30800000000000000002'))->toBeTrue();

    $claimed = $this->withToken($token)
        ->postJson('/api/wdb/pairing', ['accepting' => true, 'session' => $active->json('session')])
        ->assertOk()->assertJson(['status' => 'claimed', 'friend' => ['baid' => $friend->baid]])
        ->assertJsonMissingPath('access_code');
    $friendToken = $friend->user->tokens()->first();
    expect($friendToken->expires_at)->not->toBeNull()
        ->and($claimed->json('friend.token'))->toStartWith($friendToken->id.'|');

    // Re-polled before the acknowledgement: the same token, not a second one.
    $this->withToken($token)->postJson('/api/wdb/pairing', ['accepting' => true, 'session' => $active->json('session')])
        ->assertJson(['friend' => ['token' => $claimed->json('friend.token')]]);
    expect($friend->user->tokens()->count())->toBe(1);

    $this->withToken('official-token')->postJson('/api/wdb/pairing', ['accepting' => true])->assertForbidden();
});

it('ranks each chart by every player\'s best score, top three', function (): void {
    $players = collect(range(1, 4))->map(fn (int $n): Player => wdb_player('3080000000000000001'.$n));
    $token = wdb_token($players[0]);
    $sha = str_repeat('b', 64);
    $chart = WdbChart::query()->create(['sha256' => $sha]);
    foreach ([[0, 700000], [0, 900000], [1, 800000], [2, 600000], [3, 500000]] as [$index, $score]) {
        WdbPlay::query()->create([
            ...Arr::except(wdb_play($sha, ['score' => $score]), ['chart_sha256']),
            'baid' => $players[$index]->baid,
            'wdb_chart_id' => $chart->id,
            'replay' => 'inputs',
            'rescored_at' => now(),
        ]);
    }

    $rankings = $this->withToken($token)
        ->postJson('/api/wdb/rankings', ['charts' => [$sha, str_repeat('c', 64)]])
        ->assertOk()
        ->json('rankings');

    expect(array_column($rankings[$sha], 'score'))->toBe([900000, 800000, 600000])
        // The account's public name, as on the name boards (not the Don-chan's).
        ->and($rankings[$sha][0])->toMatchArray(['baid' => $players[0]->baid, 'name' => $players[0]->user->name])
        ->and($rankings[str_repeat('c', 64)])->toBe([]);
});

it('returns a player\'s best score and crown per chart', function (): void {
    $player = wdb_player();
    $other = wdb_player('30800000000000000002');
    $chart = WdbChart::query()->create(['sha256' => str_repeat('d', 64)]);
    foreach ([[$player, 800000, true, 3], [$player, 700000, true, 0], [$player, 900000, false, 9], [$other, 950000, true, 0]] as [$owner, $score, $cleared, $miss]) {
        WdbPlay::query()->create([
            ...Arr::except(wdb_play($chart->sha256, ['score' => $score, 'cleared' => $cleared, 'miss' => $miss]), ['chart_sha256']),
            'baid' => $owner->baid,
            'wdb_chart_id' => $chart->id,
            'replay' => 'inputs',
        ]);
    }

    $this->withToken(wdb_token($player))->getJson('/api/wdb/bests')
        ->assertOk()
        ->assertExactJson(['bests' => [['sha256' => $chart->sha256, 'score' => 900000, 'crown' => 2]]]);
});

it('lists the account\'s Waddamburo devices and revokes them', function (): void {
    $player = wdb_player();
    $player->user->createToken('Living room PC', ['wdb']);
    $player->user->createToken('Something else', ['other']);
    $other = wdb_player('30800000000000000002');
    $foreign = $other->user->createToken('Not mine', ['wdb'])->accessToken;

    $this->actingAs($player->user)->get('/waddamburo/settings/devices')->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('settings/Devices')
            ->has('devices', 1)->where('devices.0.name', 'Living room PC'));

    $mine = $player->user->tokens()->where('name', 'Living room PC')->firstOrFail();
    $this->actingAs($player->user)->delete("/waddamburo/settings/devices/{$mine->id}")->assertRedirect();
    $this->actingAs($player->user)->delete("/waddamburo/settings/devices/{$foreign->id}")->assertNotFound();

    expect($player->user->tokens()->where('name', 'Living room PC')->exists())->toBeFalse()
        ->and($other->user->tokens()->count())->toBe(1);
});

it('caps custom titles at the official maximum of 20 characters', function (): void {
    $player = wdb_player();
    $this->actingAs($player->user)
        ->patch('/waddamburo/settings/donchan-title', ['title' => str_repeat('あ', 21), 'titleplate_id' => 0])
        ->assertSessionHasErrors('title');
    $this->actingAs($player->user)
        ->patch('/waddamburo/settings/donchan-title', ['title' => str_repeat('あ', 20), 'titleplate_id' => 0])
        ->assertSessionHasNoErrors();
});

it('keeps the offsets a play was made with, and takes plays from clients that do not send them', function (): void {
    $player = wdb_player();
    $token = wdb_token($player);
    $with = wdb_play(str_repeat('c', 64), ['audio_offset_ms' => 12, 'input_offset_ms' => -8]);
    $without = wdb_play(str_repeat('c', 64));

    $this->withToken($token)->postJson('/api/wdb/plays', ['plays' => [$with, $without]])->assertOk();

    expect(WdbPlay::query()->find($with['id']))->audio_offset_ms->toBe(12)->input_offset_ms->toBe(-8)
        ->and(WdbPlay::query()->find($without['id']))->audio_offset_ms->toBeNull();
    $this->withToken($token)->postJson('/api/wdb/plays', ['plays' => [wdb_play(str_repeat('c', 64), ['input_offset_ms' => 9000])]])
        ->assertUnprocessable();
});

it('sends a sign-in code entered on the Play page on to its approval, and /link without one to Play', function (): void {
    $player = wdb_player();
    $userCode = $this->postJson('/api/wdb/device', ['device' => 'Waddamburo on den-pc'])->assertOk()
        ->assertJsonPath('verification_url', url('/waddamburo/play'))->json('user_code');

    $this->actingAs($player->user)->post('/green/play', ['code' => $userCode])->assertRedirect("/green/link?code={$userCode}");
    $this->actingAs($player->user)->get("/waddamburo/play?code={$userCode}")->assertRedirect("/waddamburo/link?code={$userCode}");
    $this->actingAs($player->user)->get('/green/link')->assertRedirect('/green/play');
    $this->actingAs($player->user)->post('/green/play', ['code' => '000000'])->assertSessionHasErrors('code');
});
