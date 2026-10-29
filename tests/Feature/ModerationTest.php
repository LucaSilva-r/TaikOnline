<?php

use App\Enums\SanctionType;
use App\Models\ModerationAction;
use App\Models\Player;
use App\Models\PlayerVersionStats;
use App\Models\Sanction;
use App\Models\User;
use App\Models\WdbChart;
use App\Models\WdbPlay;
use App\Services\ModerationService;
use App\Services\WaddamburoRankAggregateService;
use App\Services\WaddamburoSongs;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function mod_player(?User $user = null): Player
{
    $user ??= User::factory()->create();

    return Player::query()->create(['user_id' => $user->id, 'mydon_name' => 'どん'])->fresh('user');
}

function mod_play(Player $player, WdbChart $chart, int $score): WdbPlay
{
    return WdbPlay::query()->create([
        'id' => (string) Str::uuid(), 'baid' => $player->baid, 'wdb_chart_id' => $chart->id, 'mode' => 'normal',
        'course' => 3, 'score' => $score, 'great' => 100, 'good' => 0, 'miss' => 0, 'max_combo' => 100,
        'rolls' => 0, 'gauge' => 50, 'cleared' => true, 'scoring_version' => 1, 'engine_version' => 'test',
        'played_at' => now(), 'replay' => 'inputs', 'rescored_at' => now(),
    ]);
}

function mod_chart(): WdbChart
{
    $chart = WdbChart::query()->create(['sha256' => str_repeat('a', 64), 'title' => 'Song', 'course' => 3, 'ranked_at' => now()]);
    WaddamburoSongs::attach([['wdb_chart_id' => $chart->id, 'title' => 'Song', 'course' => 3]]);

    return $chart;
}

it('keeps shadowbanned and hidden plays off every board another player sees, but shows the cheater their own', function (): void {
    $chart = mod_chart();
    [$cheater, $hiddenPlayer, $viewer] = [mod_player(), mod_player(), mod_player()];
    $cheat = mod_play($cheater, $chart, 1_000_000);
    $hidden = mod_play($hiddenPlayer, $chart, 990_000);
    mod_play($viewer, $chart, 500_000);
    $moderation = app(ModerationService::class);
    $moderation->sanction(User::factory()->admin()->create(), $cheater, SanctionType::Shadowban, 'macro', null, null);
    $moderation->hide(User::factory()->admin()->create(), $hidden, 'autoplay');
    collect([$cheater, $hiddenPlayer, $viewer])->each(fn (Player $player) => app(WaddamburoRankAggregateService::class)->recompute($player));
    $sha = str_repeat('a', 64);
    $song = $chart->songs()->value('wdb_songs.id');

    // Another player: only their own score, everywhere.
    $token = $viewer->user->createToken('test', ['wdb'])->plainTextToken;
    $this->withToken($token)->getJson("/api/wdb/charts/{$sha}/scores")->assertOk()
        ->assertJsonCount(1, 'scores')->assertJsonPath('scores.0.baid', (int) $viewer->baid);
    $this->withToken($token)->postJson('/api/wdb/rankings', ['charts' => [$sha]])->assertOk()
        ->assertJsonCount(1, "rankings.{$sha}");
    $this->withToken($token)->getJson("/api/wdb/plays/{$cheat->id}/replay")->assertNotFound();
    $this->actingAs($viewer->user)->get("/waddamburo/songs/{$song}")->assertInertia(fn (Assert $page) => $page
        ->has('difficulties.0.entries', 1)->where('difficulties.0.play_count', 1)->has('recentPlays', 1));
    $this->actingAs($viewer->user)->get('/waddamburo/rankings')->assertInertia(fn (Assert $page) => $page
        ->has('entries', 1)->where('entries.0.user_id', $viewer->user_id));
    $this->actingAs($viewer->user)->get("/waddamburo/users/{$cheater->user_id}/board")->assertInertia(fn (Assert $page) => $page
        ->has('recentPlays', 0)->has('bestPerformances', 0)->where('summary.rank', null));
    auth()->logout();
    $this->get('/waddamburo/rankings')->assertInertia(fn (Assert $page) => $page->has('entries', 1));

    // The shadowbanned player sees their own play, first on the board.
    app('auth')->forgetGuards();
    $this->withToken($cheater->user->createToken('test', ['wdb'])->plainTextToken)->getJson("/api/wdb/charts/{$sha}/scores")
        ->assertJsonCount(2, 'scores')->assertJsonPath('scores.0.baid', (int) $cheater->baid)->assertJsonPath('mine.rank', 1);
    $this->actingAs($cheater->user)->get('/waddamburo/rankings')->assertInertia(fn (Assert $page) => $page
        ->has('entries', 2)->where('entries.0.user_id', $cheater->user_id));

    // A hidden play no longer counts in its player's standing.
    expect(PlayerVersionStats::query()->where('baid', $hiddenPlayer->baid)->value('total_score'))->toBe(0);
});

it('lets moderators sanction players but not staff, and leaves permanent bans to admins', function (): void {
    $moderator = User::factory()->moderator()->create();
    $player = mod_player();
    $staff = mod_player(User::factory()->moderator()->create());

    $this->actingAs(User::factory()->create())->get('/waddamburo/mod')->assertForbidden();
    $this->actingAs($moderator)->get('/waddamburo/mod?q='.$player->baid)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('mod/Index')->has('players.data', 1)->where('log', null));

    $this->actingAs($moderator)->post("/waddamburo/mod/players/{$staff->baid}/sanctions", ['type' => 'ban', 'reason' => 'x', 'duration_hours' => 24])
        ->assertForbidden();
    $this->actingAs($moderator)->post("/waddamburo/mod/players/{$player->baid}/sanctions", ['type' => 'ban', 'reason' => 'x'])
        ->assertForbidden();
    $this->actingAs($moderator)->post("/waddamburo/mod/players/{$player->baid}/sanctions", ['type' => 'ban', 'reason' => 'macro', 'duration_hours' => 24])
        ->assertRedirect();

    $ban = Sanction::query()->sole();
    expect($ban->created_by)->toBe($moderator->id)->and($ban->expires_at->isFuture())->toBeTrue()
        ->and(ModerationAction::query()->where('sanction_id', $ban->id)->value('action'))->toBe('ban');

    $this->actingAs(User::factory()->admin()->create())->post("/waddamburo/mod/players/{$staff->baid}/sanctions", ['type' => 'ban', 'reason' => 'x'])
        ->assertRedirect();
    expect(Sanction::query()->where('baid', $staff->baid)->value('expires_at'))->toBeNull();

    $this->actingAs($moderator)->patch("/waddamburo/mod/sanctions/{$ban->id}/revoke", ['reason' => 'appeal'])->assertRedirect();
    expect($ban->fresh()->isActive())->toBeFalse()->and($ban->fresh()->revoked_by)->toBe($moderator->id);
});

it('restricts a banned player: still logged in and playing, but unseen, uncounted and told why', function (): void {
    $chart = mod_chart();
    $player = mod_player(User::factory()->create(['username' => 'cheater', 'password' => 'password']));
    $viewer = mod_player();
    $before = mod_play($player, $chart, 900_000);
    $token = $player->user->createToken('test', ['wdb'])->plainTextToken;
    $ban = Sanction::query()->create(['baid' => $player->baid, 'type' => SanctionType::Ban, 'reason' => 'macro',
        'note_to_player' => 'Appeal on Discord.', 'expires_at' => now()->addDay()]);

    // Logins and the game keep working; every screen shows the restriction on the name board.
    $this->postJson('/api/wdb/login', ['login' => 'cheater', 'password' => 'password', 'device' => 'pc'])->assertOk();
    $this->post('/login', ['email' => 'cheater', 'password' => 'password'])->assertSessionHasNoErrors();
    $this->withToken($token)->getJson('/api/wdb/me')->assertOk()->assertJsonPath('title', 'RESTRICTED');

    // A play while restricted is kept but hidden for good.
    $id = (string) Str::uuid();
    $this->withToken($token)->postJson('/api/wdb/plays', ['plays' => [[
        'id' => $id, 'chart_sha256' => $chart->sha256, 'mode' => 'normal', 'course' => 3, 'score' => 1_000_000,
        'great' => 100, 'good' => 0, 'miss' => 0, 'max_combo' => 100, 'rolls' => 0, 'gauge' => 50, 'cleared' => true,
        'scoring_version' => 1, 'engine_version' => 'test', 'played_at' => now()->toIso8601String(), 'replay' => base64_encode('inputs'),
    ]]])->assertOk()->assertJsonPath('accepted', [$id]);
    expect(WdbPlay::query()->find($id))->hidden_reason->toBe('Played while restricted');
    expect($player->user->notifications()->sole()->data['message'])->toBe('Your play of Song will not count: your account is restricted.');

    // Others see neither their plays nor their board; they see both, with their standing.
    expect(WdbPlay::query()->visibleTo($viewer->baid)->whereKey($before->id)->exists())->toBeFalse()
        ->and(WdbPlay::query()->visibleTo($player->baid)->whereKey($before->id)->exists())->toBeTrue();
    $this->actingAs($viewer->user)->get("/waddamburo/users/{$player->user_id}/board")->assertNotFound();
    $this->actingAs($player->user)->get("/waddamburo/users/{$player->user_id}/board")
        ->assertInertia(fn (Assert $page) => $page->where('standing.0.label', 'Restriction')->where('standing.0.note', 'Appeal on Discord.')->where('standing.0.active', true));
    $this->actingAs($viewer->user)->get("/waddamburo/users/{$viewer->user_id}/board")->assertInertia(fn (Assert $page) => $page->where('standing', []));

    $ban->update(['expires_at' => now()->subMinute()]);
    $this->withToken($token)->getJson('/api/wdb/me')->assertJsonPath('title', null);
    expect(WdbPlay::query()->visibleTo($viewer->baid)->whereKey($before->id)->exists())->toBeTrue()
        ->and(WdbPlay::query()->visibleTo($viewer->baid)->whereKey($id)->exists())->toBeFalse();
});

it('hides and restores a play, logging both', function (): void {
    $chart = mod_chart();
    $player = mod_player();
    $play = mod_play($player, $chart, 900_000);
    $moderator = User::factory()->moderator()->create();

    $this->actingAs($moderator)->patch("/waddamburo/mod/plays/{$play->id}/hide", ['reason' => 'autoplay'])->assertRedirect();
    expect($play->fresh()->hidden_by)->toBe($moderator->id);
    $this->actingAs($moderator)->patch("/waddamburo/mod/plays/{$play->id}/restore")->assertRedirect();

    expect($play->fresh()->hidden_at)->toBeNull()
        ->and(ModerationAction::query()->where('wdb_play_id', $play->id)->orderBy('id')->pluck('action')->all())->toBe(['hide_play', 'restore_play'])
        ->and(PlayerVersionStats::query()->where('baid', $player->baid)->value('total_score'))->toBe(900_000);
    $this->actingAs($moderator)->get("/waddamburo/mod/players/{$player->baid}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('mod/Player')->has('plays', 1)->has('log', 2)->where('canAct', true));
});

it('lists every player for moderators to jump to, paged and sortable', function (): void {
    $moderator = User::factory()->moderator()->create();
    $named = mod_player(User::factory()->create(['name' => 'Aaron Don']));
    foreach (range(1, 55) as $index) {
        mod_player();
    }
    Player::query()->create(['mydon_name' => 'カード']); // a cabinet card never linked to an account

    $this->actingAs($moderator)->get('/waddamburo/mod')
        ->assertInertia(fn (Assert $page) => $page->has('players.data', 50)->where('players.total', 56));
    $this->actingAs($moderator)->get('/waddamburo/mod?sort=name&direction=asc')
        ->assertInertia(fn (Assert $page) => $page->where('players.data.0.baid', $named->baid)->where('filters.sort', 'name'));
    $this->actingAs($moderator)->get('/waddamburo/mod?q=aaron')
        ->assertInertia(fn (Assert $page) => $page->has('players.data', 1)->where('players.data.0.user.name', 'Aaron Don'));
});
