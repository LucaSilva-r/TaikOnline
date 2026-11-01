<?php

use App\Enums\PlayReportReason;
use App\Enums\SanctionType;
use App\Enums\UploadWarningKind;
use App\Models\BlockedEmail;
use App\Models\GameCard;
use App\Models\ModerationAction;
use App\Models\Player;
use App\Models\PlayerVersionStats;
use App\Models\Sanction;
use App\Models\User;
use App\Models\WdbChart;
use App\Models\WdbPlay;
use App\Models\WdbUploadWarning;
use App\Notifications\WaddamburoNotice;
use App\Services\ModerationService;
use App\Services\WaddamburoRankAggregateService;
use App\Services\WaddamburoUploadWarnings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config(['broadcasting.default' => 'log']);
    Storage::fake('public');
});

function deletion_player(?User $user = null, string $accessCode = '30800000000000000001'): Player
{
    $user ??= User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id, 'mydon_name' => 'どん']);
    GameCard::query()->create(['access_code' => $accessCode, 'baid' => $player->baid]);

    return $player->fresh('user');
}

function deletion_play(Player $player, WdbChart $chart): WdbPlay
{
    return WdbPlay::query()->create(['id' => (string) Str::uuid(), 'baid' => $player->baid, 'wdb_chart_id' => $chart->id, 'mode' => 'normal', 'course' => 3,
        'score' => 1000, 'great' => 1, 'good' => 0, 'miss' => 0, 'max_combo' => 1, 'rolls' => 0, 'gauge' => 50, 'cleared' => true, 'scoring_version' => 1,
        'engine_version' => 'test', 'played_at' => now(), 'replay' => 'inputs', 'rescored_at' => now()]);
}

it('deletes the account\'s player and everything about them, and nobody else\'s', function (): void {
    $staff = User::factory()->moderator()->create();
    $chart = WdbChart::query()->create(['sha256' => str_repeat('a', 64), 'ranked_at' => now()]);
    $leaver = deletion_player(User::factory()->create(['name' => 'Leaver']));
    $other = deletion_player(User::factory()->create(['name' => 'Other']), '30800000000000000002');
    $bystander = deletion_player(User::factory()->create(['name' => 'Bystander']), '30800000000000000003');
    $user = $leaver->user;
    $userId = $user->id;
    $baid = $leaver->baid;
    $reported = deletion_play($leaver, $chart);
    $theirs = deletion_play($other, $chart);
    $moderation = app(ModerationService::class);
    $moderation->report($other->user, $reported, PlayReportReason::Macro, null);
    $moderation->report($user, $theirs, PlayReportReason::Macro, 'my comment');
    $dismissed = deletion_play($leaver, $chart);
    $moderation->report($bystander->user, $dismissed, PlayReportReason::Macro, null);
    $moderation->dismissReports($staff, $dismissed, null);
    $moderation->hide($staff, deletion_play($leaver, $chart), 'macro');
    $moderation->sanction($staff, $leaver, SanctionType::Mute, 'rude', null, null);
    WaddamburoUploadWarnings::record($baid, UploadWarningKind::MalformedReplay);
    WaddamburoUploadWarnings::record($other->baid, UploadWarningKind::MalformedReplay);
    app(WaddamburoRankAggregateService::class)->recompute($leaver);
    app(WaddamburoRankAggregateService::class)->recompute($other);
    $user->createToken('game', ['wdb']);
    $user->notify(new WaddamburoNotice('For the leaver'));
    DB::table('sessions')->insert(['id' => 'leaver-session', 'user_id' => $userId, 'ip_address' => '192.0.2.1', 'payload' => '', 'last_activity' => time()]);
    DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'x', 'created_at' => now()]);
    Storage::disk('public')->put("avatars/{$userId}.png", 'png');
    expect($bystander->user->notifications()->count())->toBe(1);

    $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect('/');

    $this->assertGuest();
    expect(User::query()->find($userId))->toBeNull()
        ->and(Player::query()->find($baid))->toBeNull()
        ->and(GameCard::query()->where('baid', $baid)->count())->toBe(0)
        ->and(WdbPlay::query()->where('baid', $baid)->count())->toBe(0)
        ->and(Sanction::query()->where('baid', $baid)->count())->toBe(0)
        ->and(PlayerVersionStats::query()->where('baid', $baid)->count())->toBe(0)
        ->and(WdbUploadWarning::query()->where('baid', $baid)->count())->toBe(0)
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $userId)->count())->toBe(0)
        ->and(DB::table('notifications')->where('notifiable_id', $userId)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $userId)->count())->toBe(0)
        ->and(DB::table('password_reset_tokens')->where('email', $user->email)->count())->toBe(0)
        ->and(DB::table('play_reports')->where('comment', 'my comment')->count())->toBe(0);
    Storage::disk('public')->assertMissing("avatars/{$userId}.png");

    // Staff are left nothing naming them, nor is whoever reported them.
    expect(DB::table('notifications')->where('data', 'like', '%Leaver%')->count())->toBe(0)
        ->and($bystander->user->notifications()->count())->toBe(0);

    // The other player is intact, and the moderation log stays with the player unlinked.
    expect(WdbPlay::query()->find($theirs->id))->not->toBeNull()
        ->and(GameCard::query()->where('baid', $other->baid)->count())->toBe(1)
        ->and(PlayerVersionStats::query()->where('baid', $other->baid)->count())->toBe(1)
        ->and(WdbUploadWarning::query()->where('baid', $other->baid)->count())->toBe(1)
        ->and(ModerationAction::query()->where('action', 'mute')->sole()->baid)->toBeNull();
});

it('deletes an account without a player', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect('/');

    expect(User::query()->find($user->id))->toBeNull();
});

it('leaves a BAID that was never linked to an account alone', function (): void {
    $anonymous = Player::query()->create(['mydon_name' => 'どん']);
    $user = User::factory()->create();

    $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect('/');

    expect(Player::query()->find($anonymous->baid))->not->toBeNull();
});

it('deletes the player too when an admin deletes an account', function (): void {
    $player = deletion_player();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->delete(route('admin.players.destroy', ['taikoVersion' => 'waddamburo', 'user' => $player->user_id]))->assertRedirect();

    expect(Player::query()->find($player->baid))->toBeNull();
});

it('blocks the email of an account deleted while banned, however it is written, until the ban ends', function (): void {
    $player = deletion_player(User::factory()->create(['email' => 'don.chan@gmail.com']));
    Sanction::query()->create(['baid' => $player->baid, 'type' => SanctionType::Ban, 'reason' => 'macro', 'expires_at' => now()->addWeek()]);

    $this->actingAs($player->user)->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect('/');

    expect(DB::table('blocked_emails')->sole()->hash)->not->toContain('don');
    foreach (['don.chan@gmail.com', 'DONCHAN+alt@googlemail.com'] as $email) {
        $this->post(route('register.store'), ['name' => 'Back', 'username' => 'backagain', 'email' => $email, 'password' => 'password', 'password_confirmation' => 'password'])
            ->assertSessionHasErrors('email');
    }
    $this->post(route('register.store'), ['name' => 'Other', 'username' => 'someoneelse', 'email' => 'someone@gmail.com', 'password' => 'password', 'password_confirmation' => 'password'])
        ->assertSessionHasNoErrors();

    $this->travel(8)->days();
    expect(BlockedEmail::isBlocked('don.chan@gmail.com'))->toBeFalse();
    $this->artisan('model:prune', ['--model' => [BlockedEmail::class]])->assertSuccessful();
    expect(BlockedEmail::query()->count())->toBe(0);
});

it('keeps a permanent ban\'s block, also against changing the email of another account', function (): void {
    BlockedEmail::block('banned@example.com', null);
    $user = User::factory()->create();

    $this->travel(5)->years();
    expect(BlockedEmail::isBlocked('banned@example.com'))->toBeTrue();
    $this->actingAs($user)->patch(route('profile.update'), ['name' => $user->name, 'email' => 'banned@example.com'])->assertSessionHasErrors('email');
});

it('does not block the email of an account deleted without a ban, or after a shadowban', function (SanctionType $type): void {
    $player = deletion_player(User::factory()->create(['email' => 'left@example.com']));
    Sanction::query()->create(['baid' => $player->baid, 'type' => $type, 'reason' => 'x']);

    $this->actingAs($player->user)->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect('/');

    expect(BlockedEmail::isBlocked('left@example.com'))->toBeFalse();
})->with([SanctionType::Mute, SanctionType::Shadowban]);
