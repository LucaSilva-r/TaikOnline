<?php

use App\Enums\UploadWarningKind;
use App\Enums\UserRole;
use App\Http\Controllers\WaddamburoController;
use App\Jobs\AnalyzeWaddamburoPlays;
use App\Models\GameCard;
use App\Models\Player;
use App\Models\PlayerCosmetic;
use App\Models\PlayerVersionStats;
use App\Models\User;
use App\Models\WdbCabinet;
use App\Models\WdbChart;
use App\Models\WdbPlay;
use App\Models\WdbUploadWarning;
use App\Notifications\UploadWarningRaised;
use App\Notifications\WaddamburoNotice;
use App\Services\CabinetPairingService;
use App\Services\WaddamburoAnalyzer;
use App\Services\WaddamburoRankAggregateService;
use App\Services\WaddamburoReplay;
use App\Services\WaddamburoUploadWarnings;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/** A replay as the game writes it: version 1, one input, 2 ticks in, left Don. */
const WDB_REPLAY = "\x01\x01\x02\x00";

/** Well formed, but the faked scorer below cannot score it. */
const WDB_UNSCORABLE_REPLAY = "\x01\x01\x03\x00";

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
        'replay' => base64_encode(gzencode(WDB_REPLAY)),
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
        ->and(gzdecode(WdbPlay::query()->firstOrFail()->replay))->toBe(WDB_REPLAY);
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

        return Process::result($lines->map(fn (array $play): string => json_encode($play['replay'] === base64_encode(gzencode(WDB_UNSCORABLE_REPLAY))
            ? ['id' => $play['id'], 'error' => 'Unknown replay format.']
            : ['id' => $play['id'], 'course' => 3, 'score' => 123450, 'great' => 7, 'good' => 2, 'miss' => 1, 'max_combo' => 6,
                'rolls' => 4, 'roll_max' => 90, 'gauge' => 30, 'cleared' => false, 'scoring_version' => 3,
                'timeline' => ['notes' => [['t' => 1000, 'kind' => 'don', 'result' => 'great', 'offset' => -3]]]]))->implode("\n"));
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
        ->scoring_version->toBe(3)->rescored_at->not->toBeNull()
        ->and(WdbPlay::query()->findOrFail($early['id'])->judgementTimeline())->toBe(['notes' => [['t' => 1000, 'kind' => 'don', 'result' => 'great', 'offset' => -3]]])
        ->and(WdbPlay::query()->findOrFail($early['id']))->roll_hits->toBe(0)->roll_peak_rate->toBeNull()->roll_pad_peak_rate->toBeNull()->roll_steady_rate->toBeNull()
        ->and(WdbChart::query()->where('sha256', $sha)->value('roll_max'))->toBe(90)
        ->and($runs)->toBe([[PHP_BINARY, '--rescore'], [PHP_BINARY, '--rescore']]); // the chart tried, then the play
    $this->withToken($token)->getJson("/api/wdb/charts/{$sha}/scores")->assertJsonPath('scores.0.score', 123450);

    // Later plays score on upload; one whose replay does not score stays off the boards.
    $later = wdb_play($sha, ['score' => 999999]);
    $broken = wdb_play($sha, ['score' => 999999, 'replay' => base64_encode(gzencode(WDB_UNSCORABLE_REPLAY))]);
    $this->withToken($token)->postJson('/api/wdb/plays', ['plays' => [$later, $broken]])->assertJson(['missing_charts' => []]);
    expect(WdbPlay::query()->findOrFail($later['id'])->score)->toBe(123450)
        ->and(WdbPlay::query()->findOrFail($broken['id'])->rescored_at)->toBeNull();
    $this->withToken($token)->getJson("/api/wdb/charts/{$sha}/scores")->assertJsonPath('scores.0.score', 123450);

    // A rules change: the command rescores every play.
    $this->artisan('app:rescore-waddamburo-plays', ['--all' => true])->assertSuccessful();
    expect($runs)->toHaveCount(4);
});

it('scores a chart\'s plays in batches, so the scorer\'s timelines stay small', function (): void {
    config(['services.waddamburo.scorer' => PHP_BINARY]); // any executable: the process is faked
    $batches = [];
    Process::fake(function (PendingProcess $process) use (&$batches) {
        $lines = collect(explode("\n", trim((string) $process->input)))->map(fn (string $line): array => json_decode($line, true));
        $batches[] = $lines->count();

        return Process::result($lines->map(fn (array $play): string => json_encode(['id' => $play['id'], 'course' => 3,
            'score' => 123450, 'great' => 7, 'good' => 2, 'miss' => 1, 'max_combo' => 6, 'rolls' => 4, 'gauge' => 30,
            'cleared' => false, 'scoring_version' => 3]))->implode("\n"));
    });
    $token = wdb_token(wdb_player());
    $notes = 'WDBC canonical notes';
    $sha = hash('sha256', $notes);
    foreach (range(1, 3) as $upload) {
        $this->withToken($token)->postJson('/api/wdb/plays', ['plays' => array_map(fn (): array => wdb_play($sha), range(1, 40))])->assertOk();
    }

    $this->withToken($token)->putJson("/api/wdb/charts/{$sha}", ['notes' => base64_encode(gzencode($notes))])->assertNoContent();

    expect($batches)->toBe([1, 50, 50, 20])
        ->and(WdbPlay::query()->whereNull('rescored_at')->count())->toBe(0);
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
            'rescored_at' => now(),
        ]);
    }
    // Not scored by the server: its claimed numbers count for nothing.
    WdbPlay::query()->create([
        ...Arr::except(wdb_play($chart->sha256, ['score' => 999999]), ['chart_sha256']),
        'baid' => $player->baid, 'wdb_chart_id' => $chart->id, 'replay' => 'inputs',
    ]);

    $this->withToken(wdb_token($player))->getJson('/api/wdb/bests')
        ->assertOk()
        ->assertJsonPath('bests', [['sha256' => $chart->sha256, 'score' => 900000, 'crown' => 2]]);
});

it('lists a player\'s last plays, newest first, with the bests', function (): void {
    $player = wdb_player();
    $chart = WdbChart::query()->create(['sha256' => str_repeat('e', 64)]);
    foreach ([['2026-11-01T10:00:00Z', 3, null], ['2026-11-03T10:00:00Z', 2, null], ['2026-11-02T10:00:00Z', 1, now()]] as [$at, $course, $hidden]) {
        WdbPlay::query()->create([
            ...Arr::except(wdb_play($chart->sha256, ['course' => $course, 'played_at' => $at]), ['chart_sha256']),
            'baid' => $player->baid, 'wdb_chart_id' => $chart->id, 'replay' => 'inputs', 'hidden_at' => $hidden,
        ]);
    }

    $recent = $this->withToken(wdb_token($player))->getJson('/api/wdb/bests')->assertOk()->json('recent');

    // A hidden play is left out.
    expect(array_column($recent, 'course'))->toBe([2, 3])
        ->and($recent[0]['sha256'])->toBe($chart->sha256)
        ->and($recent[0]['played_at'])->toStartWith('2026-11-03T10:00:00');
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

it('accepts a failed play without keeping it', function (): void {
    $failed = wdb_play(str_repeat('a', 64), ['cleared' => false]);

    $this->withToken(wdb_token(wdb_player()))->postJson('/api/wdb/plays', ['plays' => [$failed]])
        ->assertOk()->assertJsonPath('accepted', [$failed['id']]);

    expect(WdbPlay::query()->count())->toBe(0);
});

it('keeps every AI battle, failed or not, and lists them with the bests', function (): void {
    $player = wdb_player();
    $token = wdb_token($player);
    $battle = ['mode' => 'ai_battle', 'ai_won' => true, 'ai_sections' => 'WLWWL', 'ai_support' => 0, 'ai_points' => 3,
        'hit_median_ms' => 12.5, 'hit_variance' => 400];
    $cleared = wdb_play(str_repeat('a', 64), $battle);
    $failed = wdb_play(str_repeat('b', 64), [...$battle, 'cleared' => false, 'ai_points' => 0, 'played_at' => '2026-09-24T20:00:00Z']);

    $this->withToken($token)->postJson('/api/wdb/plays', ['plays' => [$cleared, $failed, $cleared]])
        ->assertOk()->assertJsonPath('accepted', [$cleared['id'], $failed['id'], $cleared['id']]);

    // Both battles retain their replays, with duplicate uploads counted once.
    expect(WdbPlay::query()->orderByDesc('played_at')->pluck('id')->all())->toBe([$cleared['id'], $failed['id']])
        ->and(gzdecode(WdbPlay::query()->findOrFail($failed['id'])->replay))->toBe(WDB_REPLAY)
        ->and(DB::table('wdb_ai_battles')->count())->toBe(2);
    $plays = $this->withToken($token)->getJson('/api/wdb/bests')->assertOk()->json('ai_plays');
    // Legacy client outcomes remain recorded, but never become verified online progression.
    expect($plays)->toBe([]);
});

it('rescores a failed AI clear and makes its retained replay available', function (): void {
    config(['services.waddamburo.scorer' => PHP_BINARY]);
    Process::fake(function (PendingProcess $process) {
        $play = json_decode(trim((string) $process->input), true);

        return Process::result(json_encode(['id' => $play['id'], 'course' => 3, 'score' => 1000, 'great' => 2, 'good' => 1,
            'miss' => 3, 'max_combo' => 2, 'rolls' => 0, 'gauge' => 10, 'cleared' => false, 'scoring_version' => 1]));
    });
    $player = wdb_player();
    $sha = str_repeat('a', 64);
    WdbChart::query()->create(['sha256' => $sha, 'notes' => gzencode('synthetic chart')]);
    $play = wdb_play($sha, ['mode' => 'ai_battle', 'cleared' => false, 'score' => 1000, 'great' => 2, 'good' => 1,
        'miss' => 3, 'max_combo' => 2, 'rolls' => 0, 'gauge' => 10, 'seed' => 1234,
        'ai_won' => true, 'ai_sections' => 'WLWWL', 'ai_support' => 0, 'ai_points' => 0]);

    $this->withToken(wdb_token($player))->postJson('/api/wdb/plays', ['plays' => [$play]])
        ->assertOk()->assertJsonPath('accepted', [$play['id']]);

    expect(WdbPlay::query()->findOrFail($play['id']))->cleared->toBeFalse()->score->toBe(1000)
        ->rescored_at->not->toBeNull()->seed->toBe(1234);
    Process::assertRan(function (PendingProcess $process) use ($play): bool {
        $sent = json_decode(trim((string) $process->input), true);

        return $sent['id'] === $play['id'] && $sent['seed'] === 1234 && $sent['replay'] === $play['replay'];
    });
    $this->getJson("/api/wdb/plays/{$play['id']}/replay")
        ->assertOk()->assertJsonPath('replay', $play['replay'])->assertJsonPath('cleared', false);
});

it('rejects a failed AI battle with a malformed replay before keeping its outcome', function (): void {
    Notification::fake();
    $player = wdb_player();
    $play = wdb_play(str_repeat('a', 64), ['mode' => 'ai_battle', 'cleared' => false,
        'ai_won' => true, 'ai_sections' => 'WWW', 'ai_points' => 3, 'replay' => base64_encode('not a replay')]);

    $this->withToken(wdb_token($player))->postJson('/api/wdb/plays', ['plays' => [$play]])
        ->assertOk()->assertJsonPath('accepted', []);

    $this->assertDatabaseCount('wdb_plays', 0);
    $this->assertDatabaseCount('wdb_ai_battles', 0);
    Notification::assertSentTo($player->user, WaddamburoNotice::class,
        fn (WaddamburoNotice $notice): bool => $notice->kind === 'play_rejected');
});

it('keeps failed AI battle replays when culling ordinary failed plays', function (): void {
    $player = wdb_player();
    $sha = str_repeat('a', 64);
    $battle = wdb_play($sha, ['mode' => 'ai_battle', 'cleared' => false,
        'ai_won' => false, 'ai_sections' => 'LLL', 'ai_points' => 0]);
    $this->withToken(wdb_token($player))->postJson('/api/wdb/plays', ['plays' => [$battle]])->assertOk();
    $chart = WdbChart::query()->where('sha256', $sha)->firstOrFail();
    $normal = WdbPlay::query()->create([
        ...Arr::except(wdb_play($sha, ['cleared' => false]), ['chart_sha256', 'replay']),
        'baid' => $player->baid, 'wdb_chart_id' => $chart->id, 'replay' => gzencode(WDB_REPLAY),
    ]);

    $this->artisan('app:cull-failed-waddamburo-plays --dry-run')->assertSuccessful();
    $this->assertModelExists($normal);
    $this->artisan('app:cull-failed-waddamburo-plays')->assertSuccessful();

    $this->assertModelMissing($normal);
    expect(gzdecode(WdbPlay::query()->findOrFail($battle['id'])->replay))->toBe(WDB_REPLAY);
    $this->assertDatabaseHas('wdb_ai_battles', ['id' => $battle['id']]);
});

it('refuses an AI battle without its outcome', function (): void {
    $this->withToken(wdb_token(wdb_player()))
        ->postJson('/api/wdb/plays', ['plays' => [wdb_play(str_repeat('a', 64), ['mode' => 'ai_battle'])]])
        ->assertUnprocessable();
});

it('refuses a malformed replay without keeping or scoring it, and tells the player', function (string $replay): void {
    config(['services.waddamburo.scorer' => PHP_BINARY]); // any executable: the process is faked
    Process::fake();
    Notification::fake();
    $player = wdb_player();
    $sha = str_repeat('a', 64);
    WdbChart::query()->create(['sha256' => $sha, 'notes' => gzencode('notes')]);
    $good = wdb_play($sha);

    $this->withToken(wdb_token($player))
        ->postJson('/api/wdb/plays', ['plays' => [wdb_play($sha, ['replay' => base64_encode($replay)]), $good]])
        ->assertOk()->assertJsonPath('accepted', [$good['id']]);

    expect(WdbPlay::query()->pluck('id')->all())->toBe([$good['id']]);
    Notification::assertSentToTimes($player->user, WaddamburoNotice::class, 1);
    Notification::assertSentTo($player->user, WaddamburoNotice::class, fn (WaddamburoNotice $notice): bool => $notice->kind === 'play_rejected');
})->with([
    'not gzip' => ['inputs'],
    'over the size limit' => [gzencode(str_repeat("\0", WaddamburoReplay::MAX_BYTES + 1))],
    'unknown version' => [gzencode("\x02\x00")],
    'cut short' => [gzencode("\x01\x02\x02\x00")],
    'a bomb past the first gzip member' => [gzencode("\x01\xFF\xFF\x0F").gzencode(str_repeat("\0", 1024))],
    'more inputs than can fit' => [gzencode("\x01\xFF\xFF\xFF\xFF\x07")],
    'negative count' => [gzencode("\x01\xFF\xFF\xFF\xFF\x0F")],
    'bytes after the inputs' => [gzencode(WDB_REPLAY.'junk')],
    'unknown action' => [gzencode("\x01\x01\x02\x04")],
    'time moving backwards' => [gzencode("\x01\x02\x02\x00".str_repeat("\xFF", 9)."\x01\x00")],
    'time past a day' => [gzencode("\x01\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\x7F\x00")],
]);

it('takes an input before the song and keeps only what it checked of a replay', function (): void {
    $player = wdb_player();
    $sha = str_repeat('a', 64);
    $beforeTheSong = "\x01\x01".str_repeat("\xFF", 9)."\x01\x00";
    $play = wdb_play($sha, ['replay' => base64_encode(gzencode($beforeTheSong).gzencode('a later member'))]);

    $this->withToken(wdb_token($player))->postJson('/api/wdb/plays', ['plays' => [$play]])
        ->assertOk()->assertJsonPath('accepted', [$play['id']]);

    expect(WdbPlay::query()->findOrFail($play['id'])->replay)->toBe(gzencode($beforeTheSong));
});

it('keeps only the checked gzip member of an uploaded chart', function (): void {
    $notes = 'WDBC canonical notes';
    $sha = hash('sha256', $notes);

    $this->withToken(wdb_token(wdb_player()))
        ->putJson("/api/wdb/charts/{$sha}", ['notes' => base64_encode(gzencode($notes).gzencode(str_repeat("\0", 1024)))])
        ->assertNoContent();

    expect(WdbChart::query()->where('sha256', $sha)->value('notes'))->toBe(gzencode($notes));
});

it('turns numbers the database cannot hold away with a validation error', function (array $field): void {
    $this->withToken(wdb_token(wdb_player()))
        ->postJson('/api/wdb/plays', ['plays' => [wdb_play(str_repeat('a', 64), $field)]])
        ->assertUnprocessable();
})->with([
    'score' => [['score' => 2147483648]],
    'seed' => [['seed' => 2147483648]],
]);

it('hides another player\'s copied replay without telling the uploader, and warns moderators', function (): void {
    Notification::fake();
    $sha = str_repeat('a', 64);
    $original = wdb_play($sha);
    $copy = wdb_play($sha);
    $owner = wdb_player();
    $copier = wdb_player('30800000000000000002');

    $this->withToken(wdb_token($owner))->postJson('/api/wdb/plays', ['plays' => [$original, wdb_play($sha)]])->assertOk();
    app('auth')->forgetGuards(); // the previous request's user stays set inside one test
    $this->withToken(wdb_token($copier))->postJson('/api/wdb/plays', ['plays' => [$copy]])
        ->assertOk()->assertJsonPath('accepted', [$copy['id']]);

    expect(WdbPlay::query()->find($original['id'])->hidden_at)->toBeNull()
        ->and(WdbPlay::query()->find($copy['id'])->hidden_reason)->toStartWith('Copy of play')
        ->and(WdbUploadWarning::query()->sole())->baid->toBe($copier->baid)->kind->toBe(UploadWarningKind::CopiedReplay)->play_id->toBe($copy['id']);
    Notification::assertNotSentTo($copier->user, WaddamburoNotice::class);
});

it('counts bad uploads per player, kind and day, and tells staff once', function (): void {
    Notification::fake();
    $staff = User::factory()->create(['role' => UserRole::Moderator]);
    $player = wdb_player();
    $malformed = fn (): array => wdb_play(str_repeat('a', 64), ['replay' => base64_encode('junk')]);

    $this->withToken(wdb_token($player))->postJson('/api/wdb/plays', ['plays' => [$malformed(), $malformed()]])->assertOk();
    $this->withToken(wdb_token($player))->postJson('/api/wdb/plays', ['plays' => [$malformed()]])->assertOk();

    expect(WdbUploadWarning::query()->sole())->count->toBe(3)->kind->toBe(UploadWarningKind::MalformedReplay);
    Notification::assertSentToTimes($staff, UploadWarningRaised::class, 1);
});

it('warns about a play the scorer cannot score, or that differs from what it claims', function (string $replay, UploadWarningKind $kind): void {
    config(['services.waddamburo.scorer' => PHP_BINARY]); // any executable: the process is faked
    Process::fake(fn (PendingProcess $process) => Process::result(collect(explode("\n", trim((string) $process->input)))
        ->map(fn (string $line): array => json_decode($line, true))
        ->map(fn (array $play): string => json_encode($play['replay'] === base64_encode(gzencode(WDB_UNSCORABLE_REPLAY))
            ? ['id' => $play['id'], 'error' => 'Unknown replay format.']
            : ['id' => $play['id'], 'course' => 3, 'score' => 5, 'great' => 500, 'good' => 10, 'miss' => 0, 'max_combo' => 510, 'rolls' => 20, 'gauge' => 50, 'cleared' => true, 'scoring_version' => 1]))
        ->implode("\n")));
    $player = wdb_player();
    $sha = str_repeat('a', 64);
    WdbChart::query()->create(['sha256' => $sha, 'notes' => gzencode('notes')]);

    $this->withToken(wdb_token($player))->postJson('/api/wdb/plays', ['plays' => [wdb_play($sha, ['replay' => base64_encode(gzencode($replay))])]])->assertOk();

    expect(WdbUploadWarning::query()->sole())->baid->toBe($player->baid)->kind->toBe($kind);
})->with([
    'unscorable' => [WDB_UNSCORABLE_REPLAY, UploadWarningKind::UnscorableReplay],
    'claimed score differs' => [WDB_REPLAY, UploadWarningKind::ScoreMismatch],
]);

it('keeps a play that crashes the scorer from failing the rest of its batch', function (): void {
    config(['services.waddamburo.scorer' => PHP_BINARY]); // any executable: the process is faked
    Process::fake(function (PendingProcess $process) {
        $lines = collect(explode("\n", trim((string) $process->input)))->map(fn (array|string $line): array => json_decode($line, true));
        if ($lines->contains(fn (array $play): bool => $play['replay'] === base64_encode(gzencode(WDB_UNSCORABLE_REPLAY)))) {
            return Process::result(errorOutput: 'Index was outside the bounds of the array.', exitCode: 1);
        }

        return Process::result($lines->map(fn (array $play): string => json_encode(['id' => $play['id'], 'course' => 3, 'score' => 7, 'great' => 500, 'good' => 10,
            'miss' => 0, 'max_combo' => 510, 'rolls' => 20, 'gauge' => 50, 'cleared' => true, 'scoring_version' => 9]))->implode("\n"));
    });
    $sha = str_repeat('a', 64);
    WdbChart::query()->create(['sha256' => $sha, 'notes' => gzencode('notes')]);
    $crashing = wdb_play($sha, ['replay' => base64_encode(gzencode(WDB_UNSCORABLE_REPLAY))]);
    $fine = wdb_play($sha);

    $this->withToken(wdb_token(wdb_player()))->postJson('/api/wdb/plays', ['plays' => [$crashing, $fine]])->assertOk();

    expect(WdbPlay::query()->find($fine['id'])->score)->toBe(7)
        ->and(WdbPlay::query()->find($crashing['id'])->rescored_at)->toBeNull();
});

it('leaves a score too big for its column unscored', function (): void {
    config(['services.waddamburo.scorer' => PHP_BINARY]); // any executable: the process is faked
    Process::fake(fn (PendingProcess $process) => Process::result(json_encode(['id' => json_decode(trim((string) $process->input), true)['id'], 'course' => 3, 'score' => 107374182200,
        'great' => 1, 'good' => 0, 'miss' => 0, 'max_combo' => 1, 'rolls' => 0, 'gauge' => 50, 'cleared' => true, 'scoring_version' => 9])));
    $sha = str_repeat('a', 64);
    WdbChart::query()->create(['sha256' => $sha, 'notes' => gzencode('notes')]);
    $play = wdb_play($sha);

    $this->withToken(wdb_token(wdb_player()))->postJson('/api/wdb/plays', ['plays' => [$play]])->assertOk();

    expect(WdbPlay::query()->find($play['id'])->rescored_at)->toBeNull()
        ->and(WdbUploadWarning::query()->sole()->kind)->toBe(UploadWarningKind::UnscorableReplay);
});

it('does not keep chart notes the scorer cannot play, warns moderators, and lets the game carry on', function (array $scorerResult): void {
    config(['services.waddamburo.scorer' => PHP_BINARY]); // any executable: the process is faked
    Process::fake(fn () => Process::result(...$scorerResult));
    $player = wdb_player();
    $notes = 'hostile notes';
    $sha = hash('sha256', $notes);

    $this->withToken(wdb_token($player))->putJson("/api/wdb/charts/{$sha}", ['notes' => base64_encode(gzencode($notes))])
        ->assertNoContent();

    expect(WdbChart::query()->where('sha256', $sha)->value('notes'))->toBeNull()
        ->and(WdbUploadWarning::query()->sole())->baid->toBe($player->baid)->kind->toBe(UploadWarningKind::BadChart);
})->with([
    'a clean error' => [['output' => '{"id":"chart","error":"Chart events must be ordered by time."}']],
    'a crash' => [['errorOutput' => 'Index was outside the bounds of the array.', 'exitCode' => 1]],
]);

it('refuses chart notes past the size limit', function (): void {
    $notes = str_repeat("\0", WaddamburoController::MAX_CHART_BYTES + 1);
    $sha = hash('sha256', $notes);

    $this->withToken(wdb_token(wdb_player()))->putJson("/api/wdb/charts/{$sha}", ['notes' => base64_encode(gzencode($notes))])
        ->assertUnprocessable();
});

it('shows staff the upload warnings, and nobody else', function (): void {
    $staff = User::factory()->create(['role' => UserRole::Admin, 'two_factor_secret' => 'x', 'two_factor_confirmed_at' => now()]);
    $player = wdb_player();
    WaddamburoUploadWarnings::record($player->baid, UploadWarningKind::MalformedReplay, (string) Str::uuid(), 'Dan run');

    $this->actingAs($staff)->get('/waddamburo/mod/upload-warnings')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('mod/UploadWarnings')
            ->where('warnings.0.kind', 'Malformed replay')->where('warnings.0.count', 1)
            ->where('warnings.0.player.baid', $player->baid)->where('warnings.0.play_id', null));
    $this->actingAs($player->user)->get('/waddamburo/mod/upload-warnings')->assertForbidden();
});

function wdb_scored_play(Player $player, WdbChart $chart, int $score = 1000): WdbPlay
{
    return WdbPlay::query()->create([
        ...Arr::except(wdb_play($chart->sha256, ['score' => $score]), ['chart_sha256']),
        'baid' => $player->baid, 'wdb_chart_id' => $chart->id, 'replay' => gzencode(WDB_REPLAY),
        'timeline' => gzencode('{}'), 'rescored_at' => now(),
    ]);
}

function wdb_analyzer_answers(array $answer): void
{
    config(['services.waddamburo.analyzer' => PHP_BINARY]); // any executable: the process is faked
    Process::fake(fn (PendingProcess $process) => Process::result(collect(explode("\n", trim((string) $process->input)))
        ->map(fn (string $line): string => json_encode(['id' => json_decode($line, true)['id'], ...$answer]))->implode("\n")));
}

it('queues plays for the analyzer once scored, only when there is one', function (?string $analyzer, bool $queued): void {
    Queue::fake();
    config(['services.waddamburo.scorer' => PHP_BINARY, 'services.waddamburo.analyzer' => $analyzer]);
    Process::fake(fn (PendingProcess $process) => Process::result(json_encode(['id' => json_decode(trim((string) $process->input), true)['id'], 'course' => 3, 'score' => 7,
        'great' => 1, 'good' => 0, 'miss' => 0, 'max_combo' => 1, 'rolls' => 0, 'gauge' => 50, 'cleared' => true, 'scoring_version' => 9])));
    $sha = str_repeat('a', 64);
    WdbChart::query()->create(['sha256' => $sha, 'notes' => gzencode('notes')]);
    $play = wdb_play($sha);

    $this->withToken(wdb_token(wdb_player()))->postJson('/api/wdb/plays', ['plays' => [$play]])->assertOk();

    $queued
        ? Queue::assertPushed(AnalyzeWaddamburoPlays::class, fn (AnalyzeWaddamburoPlays $job): bool => $job->playIds === [$play['id']])
        : Queue::assertNotPushed(AnalyzeWaddamburoPlays::class);
})->with([
    'an analyzer' => [PHP_BINARY, true],
    'none' => [null, false],
]);

it('stores the analyzer\'s flag, warns moderators once, and does not tell the player', function (): void {
    Notification::fake();
    wdb_analyzer_answers(['flag' => 'review', 'analysis' => ['private' => 1]]);
    $player = wdb_player();
    $chart = WdbChart::query()->create(['sha256' => str_repeat('a', 64), 'notes' => gzencode('notes')]);
    $play = wdb_scored_play($player, $chart);
    $other = wdb_scored_play(wdb_player('30800000000000000002'), $chart);

    (new AnalyzeWaddamburoPlays([$play->id]))->handle(app(WaddamburoAnalyzer::class));
    (new AnalyzeWaddamburoPlays([$play->id]))->handle(app(WaddamburoAnalyzer::class));

    expect($play->fresh())->analysis_flag->toBe('review')->analysis->toBe(['private' => 1])->analyzed_at->not->toBeNull()
        ->and(WdbUploadWarning::query()->sole())->kind->toBe(UploadWarningKind::AnalyzerFlag)->count->toBe(1)->play_id->toBe($play->id);
    Process::assertRan(function (PendingProcess $process) use ($other): bool {
        $sent = json_decode(trim((string) $process->input), true);

        return array_column($sent['others'], 'id') === [$other->id] && gzdecode(base64_decode($sent['timeline'])) === '{}';
    });
    Notification::assertNotSentTo($player->user, WaddamburoNotice::class);
});

it('keeps a play unanalyzed when the analyzer gives an error or crashes', function (array $answer): void {
    config(['services.waddamburo.analyzer' => PHP_BINARY]); // any executable: the process is faked
    $player = wdb_player();
    $chart = WdbChart::query()->create(['sha256' => str_repeat('a', 64), 'notes' => gzencode('notes')]);
    $play = wdb_scored_play($player, $chart);
    Process::fake(fn () => Process::result(...($answer['line'] ?? false ? ['output' => json_encode(['id' => $play->id, 'error' => 'bad'])] : $answer)));

    expect(app(WaddamburoAnalyzer::class)->analyze(new EloquentCollection([$play])))->toMatchArray(['analyzed' => 0, 'failed' => 1])
        ->and($play->fresh()->analyzed_at)->toBeNull();
})->with([
    'an error' => [['line' => true]],
    'a crash' => [['exitCode' => 1]],
]);

it('analyzes the plays not analyzed yet from the command, or every one with --all', function (): void {
    wdb_analyzer_answers(['flag' => 'none']);
    $chart = WdbChart::query()->create(['sha256' => str_repeat('a', 64), 'notes' => gzencode('notes')]);
    $player = wdb_player();
    $done = wdb_scored_play($player, $chart);
    $done->update(['analyzed_at' => now()->subDay(), 'analysis_flag' => 'none']);
    $pending = wdb_scored_play($player, $chart);

    $this->artisan('app:analyze-waddamburo-plays')->assertSuccessful();
    expect($pending->fresh()->analyzed_at)->not->toBeNull()
        ->and($done->fresh()->analyzed_at->isToday())->toBeFalse();

    $this->artisan('app:analyze-waddamburo-plays', ['--all' => true])->assertSuccessful();
    expect($done->fresh()->analyzed_at->isToday())->toBeTrue();
});

it('fails the command without an analyzer', function (): void {
    $this->artisan('app:analyze-waddamburo-plays')->assertFailed();
});

it('counts crowns in a player\'s totals only for ranked charts and plays the server scored', function (): void {
    $player = wdb_player();
    $ranked = WdbChart::query()->create(['sha256' => str_repeat('a', 64), 'ranked_at' => now()]);
    $unranked = WdbChart::query()->create(['sha256' => str_repeat('b', 64)]);
    $unscored = WdbChart::query()->create(['sha256' => str_repeat('c', 64), 'ranked_at' => now()]);
    wdb_scored_play($player, $ranked);
    wdb_scored_play($player, $unranked);
    wdb_scored_play($player, $unscored)->update(['rescored_at' => null]);

    app(WaddamburoRankAggregateService::class)->recompute($player);

    expect(PlayerVersionStats::query()->where('baid', $player->baid)->sole())
        ->crown_gold->toBe(1)->crown_clear->toBe(0)->crown_dondaful->toBe(0)->ranked_song_count->toBe(1);
});

it('labels a play the server has not scored in the recent plays', function (): void {
    $player = wdb_player();
    $chart = WdbChart::query()->create(['sha256' => str_repeat('a', 64), 'ranked_at' => now(), 'title' => 'Song', 'source' => 'Stock']);
    wdb_scored_play($player, $chart)->update(['rescored_at' => null]);
    wdb_scored_play($player, $chart)->update(['played_at' => now()->subYear()]);

    $this->actingAs($player->user)->get("/waddamburo/users/{$player->user_id}/board")->assertInertia(fn (Assert $page) => $page
        ->where('recentPlays.0.awaiting_verification', true)->where('recentPlays.1.awaiting_verification', false));
});
