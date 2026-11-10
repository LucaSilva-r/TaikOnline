<?php

use App\Enums\SanctionType;
use App\Models\Player;
use App\Models\Sanction;
use App\Models\User;
use App\Models\WdbChart;
use App\Models\WdbPlay;
use App\Notifications\WaddamburoNotice;
use App\Services\ModerationService;
use App\Services\WaddamburoAiBattles;
use App\Services\WaddamburoReplay;
use App\Services\WaddamburoScorer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function ai_fixture(bool $approved = true): array
{
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);
    $chart = WdbChart::query()->create(['sha256' => str_repeat('a', 64), 'course' => 3, 'level' => 8,
        'source' => 'Stock', 'notes' => gzencode('synthetic chart'), 'ranked_at' => $approved ? now() : null]);

    return [$player, $chart, $user->createToken('AI test', ['wdb'])->plainTextToken];
}

function ai_setup(WdbChart $chart, array $overrides = []): array
{
    return ['chart_sha256' => $chart->sha256, 'base_sha256' => $chart->sha256, 'course' => 3,
        'options' => 0, 'support_level' => 0, 'version' => 1, ...$overrides];
}

function ai_play(array $session, array $overrides = []): array
{
    return ['id' => (string) Str::uuid(), 'chart_sha256' => $session['chart_sha256'], 'mode' => 'ai_battle',
        'course' => $session['course'], 'options' => $session['options'], 'seed' => $session['seed'],
        'score' => 123, 'great' => 2, 'good' => 0, 'miss' => 1, 'max_combo' => 2, 'rolls' => 0, 'gauge' => 50,
        'cleared' => true, 'scoring_version' => 9, 'engine_version' => 'test', 'played_at' => now()->toIso8601String(),
        'replay' => base64_encode(gzencode("\x01\x01\x02\x00")),
        'ai_session_id' => $session['id'], 'ai_support' => $session['support_level'],
        'ai_won' => false, 'ai_sections' => 'LLL', 'ai_points' => 3, 'hit_median_ms' => 999, 'hit_variance' => 999999,
        ...$overrides];
}

function ai_scorer(bool $cleared = true, ?string $sections = 'WWW'): void
{
    config(['services.waddamburo.scorer' => PHP_BINARY]);
    Process::fake(function (PendingProcess $process) use ($cleared, $sections) {
        $plays = collect(explode("\n", trim((string) $process->input)));

        return Process::result($plays->map(function (string $line) use ($cleared, $sections): string {
            $play = json_decode($line, true);

            return json_encode(['id' => $play['id'], 'course' => 3, 'score' => 123, 'great' => 2, 'good' => 0, 'miss' => 1,
                'max_combo' => 2, 'rolls' => 0, 'gauge' => 50, 'cleared' => $cleared, 'scoring_version' => 9,
                'ai_battle' => $play['ai_battle'] === null || $sections === null ? null : ['version' => $play['ai_battle']['version'], 'sections' => $sections, 'level' => 8,
                    'hit_median_ms' => 12.5, 'hit_variance' => 400, 'counts' => []]]);
        })->implode("\n"));
    });
}

it('requires authentication for a server-issued battle setup', function (): void {
    $this->postJson('/api/wdb/ai/battles', [])->assertUnauthorized();
});

it('reuses an unplayed setup instead of letting a client draw another seed', function (): void {
    [$player, $chart, $token] = ai_fixture();
    $first = $this->withToken($token)->postJson('/api/wdb/ai/battles', ai_setup($chart))->assertOk()->json();
    $again = $this->postJson('/api/wdb/ai/battles', ai_setup($chart))->assertOk()->json();

    expect($again)->toBe($first);
    $this->assertDatabaseCount('wdb_ai_sessions', 1);
    expect($first)->toMatchArray(['ranked' => true, 'points' => 0, 'wins' => 0, 'hit_median_ms' => null]);
});

it('derives the outcome and timing from the scorer and awards one uploaded battle once', function (): void {
    [$player, $chart, $token] = ai_fixture();
    ai_scorer();
    $session = $this->withToken($token)->postJson('/api/wdb/ai/battles', ai_setup($chart))->assertOk()->json();
    $play = ai_play($session);

    $this->postJson('/api/wdb/plays', ['plays' => [$play, $play]])->assertOk()->assertJsonPath('accepted', [$play['id'], $play['id']]);

    $this->assertDatabaseCount('wdb_plays', 1);
    $this->assertDatabaseHas('wdb_ai_battles', ['id' => $play['id'], 'eligible' => true, 'won' => true,
        'sections' => 'WWW', 'points' => 3, 'hit_median' => 12.5, 'hit_variance' => 400]);
    expect(app(WaddamburoAiBattles::class)->progress((int) $player->baid))->toMatchArray(['points' => 3, 'wins' => 1]);
    $this->getJson('/api/wdb/bests')->assertOk()->assertJsonCount(1, 'ai_plays')->assertJsonPath('ai_plays.0.points', 3);
    $next = $this->postJson('/api/wdb/ai/battles', ai_setup($chart))->assertOk()->json();
    expect($next)->toMatchArray(['points' => 3, 'wins' => 1, 'hit_median_ms' => 12.5, 'hit_variance' => 400]);
    expect($next['id'])->not->toBe($session['id']);
    $this->actingAs($player->user)->get("/waddamburo/plays/{$play['id']}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('WaddamburoPlay')
            ->where('aiBattle.verified', true)->where('aiBattle.counted', true)->where('aiBattle.won', true)
            ->where('aiBattle.sections', 'WWW')->where('aiBattle.points', 3)
            ->where('aiBattle.progress.points', 3)->where('aiBattle.progress.wins', 1)
            ->where('play.score', 123)->where('play.placement', 1));
    Process::assertRan(fn (PendingProcess $process): bool => json_decode(trim((string) $process->input), true)['ai_battle']['hit_median_ms'] === null);
});

it('refuses a changed seed, chart, course, options or support level', function (string $field, mixed $value): void {
    [, $chart, $token] = ai_fixture();
    $session = $this->withToken($token)->postJson('/api/wdb/ai/battles', ai_setup($chart))->assertOk()->json();
    $play = ai_play($session, [$field => $field === 'seed' ? ($session['seed'] ^ 1) : $value]);

    $this->postJson('/api/wdb/plays', ['plays' => [$play]])->assertUnprocessable()->assertJsonValidationErrors('ai_session_id');

    $this->assertDatabaseCount('wdb_plays', 0);
    $this->assertDatabaseCount('wdb_ai_battles', 0);
})->with(['seed' => ['seed', 0], 'chart' => ['chart_sha256', str_repeat('b', 64)], 'course' => ['course', 2],
    'options' => ['options', 64], 'support level' => ['ai_support', 10]]);

it('keeps a setup private to its player and binds it to one play id', function (): void {
    [$player, $chart, $token] = ai_fixture();
    $session = $this->withToken($token)->postJson('/api/wdb/ai/battles', ai_setup($chart))->assertOk()->json();
    $other = User::factory()->create();
    Player::query()->create(['user_id' => $other->id]);
    $play = ai_play($session);

    app('auth')->forgetGuards();
    $this->withToken($other->createToken('other', ['wdb'])->plainTextToken)
        ->postJson('/api/wdb/plays', ['plays' => [$play]])->assertUnprocessable();
    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/wdb/plays', ['plays' => [$play]])->assertOk();
    $this->postJson('/api/wdb/plays', ['plays' => [ai_play($session)]])->assertUnprocessable();

    $this->assertDatabaseCount('wdb_plays', 1);
    $this->assertDatabaseHas('wdb_ai_sessions', ['id' => $session['id'], 'play_id' => $play['id']]);
});

it('counts a verified battle win without a gauge clear but awards no rank points', function (): void {
    [$player, $chart, $token] = ai_fixture();
    ai_scorer(cleared: false);
    $session = $this->withToken($token)->postJson('/api/wdb/ai/battles', ai_setup($chart))->assertOk()->json();
    $play = ai_play($session, ['cleared' => false]);

    $this->postJson('/api/wdb/plays', ['plays' => [$play]])->assertOk();

    expect(app(WaddamburoAiBattles::class)->progress((int) $player->baid))->toMatchArray(['points' => 0, 'wins' => 1]);
    expect(WdbPlay::query()->findOrFail($play['id']))->replay->not->toBeNull()->rescored_at->not->toBeNull();
});

it('keeps an unapproved chart score but awards no AI progression', function (): void {
    [$player, $chart, $token] = ai_fixture(approved: false);
    ai_scorer();
    $session = $this->withToken($token)->postJson('/api/wdb/ai/battles', ai_setup($chart))->assertOk()->assertJsonPath('ranked', false)->json();
    $play = ai_play($session);

    $this->postJson('/api/wdb/plays', ['plays' => [$play]])->assertOk();

    expect(app(WaddamburoAiBattles::class)->progress((int) $player->baid))->toMatchArray(['points' => 0, 'wins' => 0]);
    expect(WdbPlay::query()->findOrFail($play['id']))->score->toBe(123)->rescored_at->not->toBeNull();
    $this->getJson('/api/wdb/bests')->assertOk()->assertJsonCount(0, 'ai_plays');
    $this->actingAs($player->user)->get("/waddamburo/plays/{$play['id']}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('aiBattle.verified', true)
            ->where('aiBattle.won', true)->where('aiBattle.counted', false)->where('aiBattle.progress.wins', 0));
});

it('verifies an offline battle from the setup it uploads with, in play order', function (): void {
    [$player, $chart, $token] = ai_fixture();
    ai_scorer();
    $offline = ['id' => '00000000-0000-0000-0000-000000000000', 'seed' => 4321, 'chart_sha256' => $chart->sha256,
        'course' => 3, 'options' => 0, 'support_level' => 0];
    $play = ai_play($offline, ['ai_session_id' => null, 'ai_prior_median_ms' => 4.5, 'ai_prior_variance' => 300,
        'played_at' => now()->subHour()->toIso8601String()]);

    $this->withToken($token)->postJson('/api/wdb/plays', ['plays' => [$play]])->assertOk()->assertJsonPath('accepted', [$play['id']]);

    $this->assertDatabaseHas('wdb_ai_sessions', ['play_id' => $play['id'], 'seed' => 4321, 'version' => 1,
        'base_sha256' => $chart->sha256, 'hit_median' => 4.5, 'hit_variance' => 300]);
    $this->assertDatabaseHas('wdb_ai_battles', ['id' => $play['id'], 'eligible' => true, 'sections' => 'WWW', 'points' => 3]);
    Process::assertRan(fn (PendingProcess $process): bool => json_decode(trim((string) $process->input), true)['ai_battle']['hit_median_ms'] === 4.5);
    // A retried upload keeps its one setup.
    $this->postJson('/api/wdb/plays', ['plays' => [$play]])->assertOk();
    expect(DB::table('wdb_ai_sessions')->where('play_id', $play['id'])->count())->toBe(1);
});

it('does not create an offline setup for an intermediate chart without its base', function (): void {
    [$player, $chart, $token] = ai_fixture();
    ai_scorer();
    $play = ai_play(['id' => null, 'seed' => 1, 'chart_sha256' => $chart->sha256, 'course' => 3, 'options' => 0, 'support_level' => 11],
        ['ai_session_id' => null]);

    $this->withToken($token)->postJson('/api/wdb/plays', ['plays' => [$play]])->assertOk();

    expect(DB::table('wdb_ai_sessions')->where('play_id', $play['id'])->exists())->toBeFalse();
    $this->assertDatabaseHas('wdb_ai_battles', ['id' => $play['id'], 'verified_at' => null, 'points' => 0]);
});

it('does not trust legacy battle claims even when the ordinary score is verified', function (): void {
    [$player, $chart, $token] = ai_fixture();
    ai_scorer();
    $session = $this->withToken($token)->postJson('/api/wdb/ai/battles', ai_setup($chart))->assertOk()->json();
    // An older client: no setup and no seed, nothing the server can replay.
    $play = ai_play($session, ['ai_session_id' => null, 'seed' => null, 'ai_won' => true, 'ai_sections' => 'WWW']);

    $this->postJson('/api/wdb/plays', ['plays' => [$play]])->assertOk();

    expect(app(WaddamburoAiBattles::class)->progress((int) $player->baid))->toMatchArray(['points' => 0, 'wins' => 0]);
    $this->assertDatabaseHas('wdb_ai_battles', ['id' => $play['id'], 'verified_at' => null, 'eligible' => false, 'points' => 0]);
    $this->actingAs($player->user)->get("/waddamburo/plays/{$play['id']}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('aiBattle.verified', false)
            ->where('aiBattle.won', null)->where('aiBattle.sections', '')->where('aiBattle.points', 0));
});

it('rebuilds first-win bonuses in setup order when charts or uploads arrive late', function (): void {
    [$player, $chart, $token] = ai_fixture();
    $chart->update(['notes' => null]);
    ai_scorer();
    $first = $this->withToken($token)->postJson('/api/wdb/ai/battles', ai_setup($chart))->assertOk()->json();
    $one = ai_play($first);
    $this->postJson('/api/wdb/plays', ['plays' => [$one]])->assertOk();
    $second = $this->postJson('/api/wdb/ai/battles', ai_setup($chart))->assertOk()->json();
    $two = ai_play($second);
    $this->postJson('/api/wdb/plays', ['plays' => [$two]])->assertOk();
    $chart->update(['notes' => gzencode('synthetic chart')]);

    app(WaddamburoScorer::class)->rescore(new Collection([WdbPlay::query()->findOrFail($two['id'])]));
    app(WaddamburoScorer::class)->rescore(new Collection([WdbPlay::query()->findOrFail($one['id'])]));

    expect(app(WaddamburoAiBattles::class)->progress((int) $player->baid))->toMatchArray(['points' => 5, 'wins' => 2]);
    $this->assertDatabaseHas('wdb_ai_battles', ['id' => $one['id'], 'points' => 3]);
    $this->assertDatabaseHas('wdb_ai_battles', ['id' => $two['id'], 'points' => 2]);
});

it('redistributes rank points when an AI play is hidden or restored', function (): void {
    [$player, $chart, $token] = ai_fixture();
    ai_scorer();
    $first = $this->withToken($token)->postJson('/api/wdb/ai/battles', ai_setup($chart))->assertOk()->json();
    $one = ai_play($first);
    $this->postJson('/api/wdb/plays', ['plays' => [$one]])->assertOk();
    $second = $this->postJson('/api/wdb/ai/battles', ai_setup($chart))->assertOk()->json();
    $two = ai_play($second);
    $this->postJson('/api/wdb/plays', ['plays' => [$two]])->assertOk();
    $admin = User::factory()->admin()->create();
    $play = WdbPlay::query()->findOrFail($one['id']);

    app(ModerationService::class)->hide($admin, $play, 'invalid play');

    expect(app(WaddamburoAiBattles::class)->progress((int) $player->baid))->toMatchArray(['points' => 3, 'wins' => 1]);
    $this->assertDatabaseHas('wdb_ai_battles', ['id' => $two['id'], 'points' => 3]);
    app(ModerationService::class)->restore($admin, $play, 'reviewed');
    expect(app(WaddamburoAiBattles::class)->progress((int) $player->baid))->toMatchArray(['points' => 5, 'wins' => 2]);
});

it('does not let a new setup bind a previously stored play', function (): void {
    [, $chart, $token] = ai_fixture();
    ai_scorer();
    $first = $this->withToken($token)->postJson('/api/wdb/ai/battles', ai_setup($chart))->assertOk()->json();
    $play = ai_play($first);
    $this->postJson('/api/wdb/plays', ['plays' => [$play]])->assertOk();
    $second = $this->postJson('/api/wdb/ai/battles', ai_setup($chart))->assertOk()->json();

    $this->postJson('/api/wdb/plays', ['plays' => [ai_play($second, ['id' => $play['id']])]])
        ->assertUnprocessable()->assertJsonValidationErrors('ai_session_id');

    $this->assertDatabaseHas('wdb_ai_sessions', ['id' => $second['id'], 'play_id' => null]);
    $this->assertDatabaseCount('wdb_plays', 1);
});

it('does not consume a setup when the replay is malformed', function (): void {
    [, $chart, $token] = ai_fixture();
    $session = $this->withToken($token)->postJson('/api/wdb/ai/battles', ai_setup($chart))->assertOk()->json();

    $this->postJson('/api/wdb/plays', ['plays' => [ai_play($session, ['replay' => base64_encode('invalid')])]])
        ->assertOk()->assertJsonCount(0, 'accepted');

    $this->assertDatabaseHas('wdb_ai_sessions', ['id' => $session['id'], 'play_id' => null]);
    $this->assertDatabaseCount('wdb_plays', 0);
});

it('retains the AI summary for an initially hidden upload and awards it if restored', function (): void {
    [$player, $chart, $token] = ai_fixture();
    ai_scorer();
    $session = $this->withToken($token)->postJson('/api/wdb/ai/battles', ai_setup($chart))->assertOk()->json();
    $play = ai_play($session);
    $originalPlayer = Player::query()->create(['user_id' => User::factory()->create()->id]);
    $replay = WaddamburoReplay::canonical($play['replay']);
    WdbPlay::query()->create([
        'id' => (string) Str::uuid(), 'baid' => $originalPlayer->baid, 'wdb_chart_id' => $chart->id,
        'mode' => 'normal', 'course' => 3, 'score' => 123, 'great' => 2, 'good' => 0, 'miss' => 1,
        'max_combo' => 2, 'rolls' => 0, 'gauge' => 50, 'cleared' => true, 'scoring_version' => 9,
        'engine_version' => 'test', 'played_at' => now(), 'replay' => $replay,
        'replay_hash' => WaddamburoReplay::hash($replay),
    ]);

    $this->postJson('/api/wdb/plays', ['plays' => [$play]])->assertOk();

    $stored = WdbPlay::query()->findOrFail($play['id']);
    expect($stored->hidden_at)->not->toBeNull();
    $this->assertDatabaseHas('wdb_ai_battles', ['id' => $play['id'], 'sections' => 'WWW', 'won' => true, 'points' => 0]);
    expect(app(WaddamburoAiBattles::class)->progress((int) $player->baid))->toMatchArray(['points' => 0, 'wins' => 0]);
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->get("/waddamburo/plays/{$play['id']}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('aiBattle.verified', true)
            ->where('aiBattle.counted', false)->where('aiBattle.points', 0));

    app(ModerationService::class)->restore($admin, $stored, 'reviewed');

    expect(app(WaddamburoAiBattles::class)->progress((int) $player->baid))->toMatchArray(['points' => 3, 'wins' => 1]);
});

it('shows AI board progress and verified sections separately from normal plays', function (bool $cleared, string $sections, bool $won, int $points, int $wins, int $rank): void {
    [$player, $chart, $token] = ai_fixture();
    $chart->update(['title' => 'Synthetic AI chart']);
    ai_scorer(cleared: $cleared, sections: $sections);
    $session = $this->withToken($token)->postJson('/api/wdb/ai/battles', ai_setup($chart))->assertOk()->json();
    $battle = ai_play($session, ['cleared' => $cleared]);
    $normal = ai_play($session, ['mode' => 'normal', 'ai_session_id' => null]);
    $this->postJson('/api/wdb/plays', ['plays' => [$battle, $normal]])->assertOk();

    $this->actingAs($player->user)->get("/waddamburo/users/{$player->user_id}/board")
        ->assertInertia(fn (Assert $page) => $page->component('Board')
            ->where('aiBattleData.progress.points', $points)->where('aiBattleData.progress.wins', $wins)
            ->where('aiBattleData.progress.rank', $rank)->has('aiBattleData.recent', 1)
            ->where('aiBattleData.recent.0.play_id', $battle['id'])->where('aiBattleData.recent.0.title', 'Synthetic AI chart')
            ->where('aiBattleData.recent.0.sections', $sections)->where('aiBattleData.recent.0.won', $won)
            ->where('aiBattleData.recent.0.verified', true)->where('aiBattleData.recent.0.counted', true)
            ->where('aiBattleData.recent.0.points', $points)->where('aiBattleData.recent.0.cleared', $cleared));
})->with([
    'battle win and gauge clear' => [true, 'WWL', true, 3, 1, 1],
    'battle loss' => [true, 'LWL', false, 0, 0, 0],
    'battle win without gauge clear' => [false, 'WLWLW', true, 0, 1, 0],
]);

it('does not expose unverified battle claims as section wins on the board', function (): void {
    [$player, $chart, $token] = ai_fixture();
    ai_scorer();
    $session = $this->withToken($token)->postJson('/api/wdb/ai/battles', ai_setup($chart))->assertOk()->json();
    // An older client: no setup and no seed, nothing the server can replay.
    $play = ai_play($session, ['ai_session_id' => null, 'seed' => null, 'ai_won' => true, 'ai_sections' => 'WWW']);
    $this->postJson('/api/wdb/plays', ['plays' => [$play]])->assertOk();

    $this->actingAs($player->user)->get("/waddamburo/users/{$player->user_id}/board")
        ->assertInertia(fn (Assert $page) => $page->has('aiBattleData.recent', 1)
            ->where('aiBattleData.recent.0.verified', false)->where('aiBattleData.recent.0.won', null)
            ->where('aiBattleData.recent.0.sections', '')->where('aiBattleData.recent.0.points', 0)
            ->where('aiBattleData.progress.points', 0)->where('aiBattleData.progress.wins', 0));
});

it('keeps hidden battles out of the AI board and shadowbanned progress private to its owner', function (): void {
    [$player, $chart, $token] = ai_fixture();
    ai_scorer();
    $session = $this->withToken($token)->postJson('/api/wdb/ai/battles', ai_setup($chart))->assertOk()->json();
    $play = ai_play($session);
    $this->postJson('/api/wdb/plays', ['plays' => [$play]])->assertOk();
    $stored = WdbPlay::query()->findOrFail($play['id']);
    $stored->update(['hidden_at' => now()]);
    $url = "/waddamburo/users/{$player->user_id}/board";
    $this->actingAs($player->user)->get($url)->assertInertia(fn (Assert $page) => $page
        ->has('aiBattleData.recent', 0)->where('aiBattleData.progress.points', 0)->where('aiBattleData.progress.wins', 0));
    $stored->update(['hidden_at' => null]);
    Sanction::query()->create(['baid' => $player->baid, 'type' => SanctionType::Shadowban, 'reason' => 'review']);

    $this->get($url)->assertInertia(fn (Assert $page) => $page
        ->has('aiBattleData.recent', 1)->where('aiBattleData.progress.points', 3)->where('aiBattleData.progress.wins', 1));
    $this->actingAs(User::factory()->create())->get($url)->assertInertia(fn (Assert $page) => $page
        ->has('aiBattleData.recent', 0)->where('aiBattleData.progress.points', 0)->where('aiBattleData.progress.wins', 0));
});

it('shows unranked AI history only to viewers who can see the chart', function (): void {
    [$player, $chart, $token] = ai_fixture(approved: false);
    ai_scorer();
    $session = $this->withToken($token)->postJson('/api/wdb/ai/battles', ai_setup($chart))->assertOk()->json();
    $play = ai_play($session);
    $this->postJson('/api/wdb/plays', ['plays' => [$play]])->assertOk();
    $url = "/waddamburo/users/{$player->user_id}/board";

    $this->actingAs($player->user)->get($url)->assertInertia(fn (Assert $page) => $page
        ->has('aiBattleData.recent', 1)->where('aiBattleData.recent.0.sections', 'WWW')
        ->where('aiBattleData.recent.0.counted', false)->where('aiBattleData.progress.wins', 0));
    $this->actingAs(User::factory()->create())->get($url)->assertInertia(fn (Assert $page) => $page
        ->has('aiBattleData.recent', 0));
});

it('limits AI board history to the newest twenty plays without mixing players', function (): void {
    [$player, $chart, $token] = ai_fixture();
    $this->freezeTime();
    ai_scorer();
    $session = $this->withToken($token)->postJson('/api/wdb/ai/battles', ai_setup($chart))->assertOk()->json();
    $plays = [];
    for ($index = 0; $index < 21; $index++) {
        $plays[] = ai_play($session, ['ai_session_id' => null, 'played_at' => now()->subMinutes($index)->toIso8601String()]);
    }
    $this->postJson('/api/wdb/plays', ['plays' => $plays])->assertOk();
    $other = User::factory()->create();
    $otherPlayer = Player::query()->create(['user_id' => $other->id]);
    app('auth')->forgetGuards();
    $this->withToken($other->createToken('other', ['wdb'])->plainTextToken)
        ->postJson('/api/wdb/plays', ['plays' => [ai_play($session, ['ai_session_id' => null, 'replay' => base64_encode(gzencode("\x01\x01\x03\x00"))])]])->assertOk();

    $this->actingAs($player->user)->get("/waddamburo/users/{$player->user_id}/board")
        ->assertInertia(fn (Assert $page) => $page->has('aiBattleData.recent', 20)
            ->where('aiBattleData.recent.0.play_id', $plays[0]['id'])
            ->where('aiBattleData.recent.19.play_id', $plays[19]['id']));
});

it('provides an empty AI board for a player without battles', function (): void {
    [$player] = ai_fixture();

    $this->actingAs($player->user)->get("/waddamburo/users/{$player->user_id}/board")
        ->assertInertia(fn (Assert $page) => $page->has('aiBattleData.recent', 0)
            ->where('aiBattleData.progress', ['points' => 0, 'wins' => 0, 'rank' => 0, 'points_to_next' => 1]));
});

it('omits the AI board for an account without a player profile', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get("/waddamburo/users/{$user->id}/board")
        ->assertInertia(fn (Assert $page) => $page->where('aiBattleData', null));
});

it('warns the player once when provisional AI progress is rejected', function (?string $sections): void {
    [$player, $chart, $token] = ai_fixture();
    Event::fake([BroadcastNotificationCreated::class]);
    ai_scorer(sections: $sections);
    $session = $this->withToken($token)->postJson('/api/wdb/ai/battles', ai_setup($chart))->assertOk()->json();
    $play = ai_play($session, ['ai_won' => true, 'ai_sections' => 'WWW']);
    $this->postJson('/api/wdb/plays', ['plays' => [$play]])->assertOk();
    app(WaddamburoScorer::class)->rescore(new Collection([WdbPlay::query()->findOrFail($play['id'])]));

    $notice = $player->user->notifications()->sole();
    expect($notice->data)->toMatchArray(['kind' => 'play_rejected', 'severity' => 'warning', 'details' => ['play_id' => $play['id']]]);
    expect(app(WaddamburoAiBattles::class)->progress((int) $player->baid))->toMatchArray(['points' => 0, 'wins' => 0]);
    Event::assertDispatchedTimes(BroadcastNotificationCreated::class, 1);
})->with(['scorer without AI verification' => [null], 'verified as a loss' => ['LLL']]);

it('does not warn for accepted, unranked or legacy AI results', function (bool $approved, bool $legacy): void {
    [$player, $chart, $token] = ai_fixture(approved: $approved);
    Notification::fake();
    ai_scorer();
    $session = $this->withToken($token)->postJson('/api/wdb/ai/battles', ai_setup($chart))->assertOk()->json();
    $play = ai_play($session, $legacy ? ['ai_session_id' => null] : []);

    $this->postJson('/api/wdb/plays', ['plays' => [$play]])->assertOk();

    Notification::assertNothingSent();
})->with(['accepted ranked battle' => [true, false], 'custom chart' => [false, false], 'legacy play' => [true, true]]);

it('warns when a ranked AI replay cannot be scored', function (): void {
    [$player, $chart, $token] = ai_fixture();
    Notification::fake();
    config(['services.waddamburo.scorer' => PHP_BINARY]);
    Process::fake(fn (PendingProcess $process) => Process::result(json_encode([
        'id' => json_decode(trim((string) $process->input), true)['id'], 'error' => 'Synthetic invalid replay',
    ])));
    $session = $this->withToken($token)->postJson('/api/wdb/ai/battles', ai_setup($chart))->assertOk()->json();
    $play = ai_play($session, ['ai_won' => true]);

    $this->postJson('/api/wdb/plays', ['plays' => [$play]])->assertOk();

    Notification::assertSentTo($player->user, WaddamburoNotice::class, fn (WaddamburoNotice $notice): bool => $notice->severity === 'warning' && $notice->kind === 'play_rejected' && $notice->details['play_id'] === $play['id']);
    $this->assertDatabaseHas('wdb_plays', ['id' => $play['id'], 'rescored_at' => null]);
    Process::assertRan(fn (PendingProcess $process): bool => str_contains((string) $process->input, $play['id']));
});

it('binds each supported AI model to its own setup and verifies old and new plays', function (int $version): void {
    [$player, $chart, $token] = ai_fixture();
    ai_scorer();
    $session = $this->withToken($token)->postJson('/api/wdb/ai/battles', ai_setup($chart, ['version' => $version]))
        ->assertOk()->assertJsonPath('version', $version)->json();
    $again = $this->postJson('/api/wdb/ai/battles', ai_setup($chart, ['version' => $version]))->assertOk()->json();
    expect($again['id'])->toBe($session['id']);
    $this->postJson('/api/wdb/ai/battles', ai_setup($chart, ['version' => 2]))->assertUnprocessable();
    $play = ai_play($session);

    $this->postJson('/api/wdb/plays', ['plays' => [$play]])->assertOk()->assertJsonPath('accepted', [$play['id']]);

    $this->assertDatabaseHas('wdb_ai_sessions', ['id' => $session['id'], 'version' => $version, 'play_id' => $play['id']]);
    $this->assertDatabaseHas('wdb_ai_battles', ['id' => $play['id'], 'eligible' => true, 'sections' => 'WWW', 'points' => 3]);
    Process::assertRan(fn (PendingProcess $process): bool => json_decode(trim((string) $process->input), true)['ai_battle']['version'] === $version);
})->with([1]);

it('does not verify a score under a different model than its server setup', function (): void {
    Notification::fake();
    [$player, $chart, $token] = ai_fixture();
    ai_scorer();
    $session = $this->withToken($token)->postJson('/api/wdb/ai/battles', ai_setup($chart, ['version' => 1]))->assertOk()->json();
    $play = ai_play($session);
    $this->postJson('/api/wdb/plays', ['plays' => [$play]])->assertOk();

    app(WaddamburoAiBattles::class)->apply(WdbPlay::findOrFail($play['id']), ['version' => 2, 'sections' => 'WWW']);

    $this->assertDatabaseHas('wdb_ai_battles', ['id' => $play['id'], 'verified_at' => null, 'points' => 0, 'eligible' => false]);
    Notification::assertSentTo($player->user, WaddamburoNotice::class);
});
