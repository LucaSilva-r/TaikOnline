<?php

use App\Events\WdbNoticePosted;
use App\Models\User;
use App\Models\WdbNotice;
use App\Notifications\WaddamburoNotice;
use Illuminate\Support\Facades\Event;

function wdb_notice_user(): User
{
    return User::factory()->create();
}

beforeEach(function (): void {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => '1',
        'broadcasting.connections.reverb.public_url' => 'wss://taikonline.test/',
    ]);
    // Channels register on the broadcaster booted with the app (null under tests).
    require base_path('routes/channels.php');
});

it('tells clients where Reverb is and which system notices are showing', function (): void {
    WdbNotice::query()->create(['message' => 'Old', 'ends_at' => now()->subMinute()]);
    WdbNotice::query()->create(['message' => 'Later', 'starts_at' => now()->addHour()]);
    WdbNotice::query()->create(['message' => 'Maintenance at 22:00', 'severity' => 'warning']);

    $this->getJson('/api/wdb/realtime')->assertOk()
        ->assertExactJson(['url' => 'wss://taikonline.test/app/test-key', 'key' => 'test-key']);
    $this->getJson('/api/wdb/notices')->assertOk()
        ->assertJsonCount(1, 'notices')->assertJsonPath('notices.0.message', 'Maintenance at 22:00')
        ->assertJsonPath('notices.0.severity', 'warning');
});

it('broadcasts a system notice from the command', function (): void {
    Event::fake([WdbNoticePosted::class]);

    $this->artisan('app:wdb-notice', ['message' => 'Updating soon', '--severity' => 'critical', '--minutes' => 10])
        ->assertSuccessful();

    Event::assertDispatched(WdbNoticePosted::class, fn (WdbNoticePosted $event) => $event->notice->message === 'Updating soon'
        && $event->broadcastOn()->name === 'wdb.notices');
    expect(WdbNotice::query()->sole()->ends_at)->not->toBeNull();
    $this->artisan('app:wdb-notice', ['message' => 'x', '--severity' => 'loud'])->assertFailed();
});

it('signs only the player\'s own private channel and keeps their notices until read', function (): void {
    $player = wdb_notice_user();
    $other = wdb_notice_user();
    $token = $player->createToken('test', ['wdb'])->plainTextToken;
    $own = 'private-App.Models.User.'.$player->id;

    $this->withToken($token)->postJson('/api/wdb/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $own])
        ->assertOk()->assertJsonPath('auth', 'test-key:'.hash_hmac('sha256', "1234.5678:{$own}", 'test-secret'));
    $this->withToken($token)->postJson('/api/wdb/broadcasting/auth', [
        'socket_id' => '1234.5678', 'channel_name' => 'private-App.Models.User.'.$other->id,
    ])->assertForbidden();
    app('auth')->forgetGuards(); // the previous request's user stays set inside one test
    $this->withoutToken()->postJson('/api/wdb/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $own])->assertUnauthorized();

    config(['broadcasting.default' => 'log']); // the pushes themselves are Reverb's job
    $player->notify(new WaddamburoNotice('Your play was not ranked', 'warning', 'play_rejected'));
    $other->notify(new WaddamburoNotice('Not yours'));
    $id = $this->withToken($token)->getJson('/api/wdb/notifications')->assertOk()
        ->assertJsonPath('channel', $own)
        ->assertJsonCount(1, 'notifications')
        ->assertJsonPath('notifications.0.message', 'Your play was not ranked')
        ->assertJsonPath('notifications.0.kind', 'play_rejected')
        ->json('notifications.0.id');

    $this->withToken($token)->postJson('/api/wdb/notifications/read', ['ids' => [$id]])->assertNoContent();
    $this->withToken($token)->getJson('/api/wdb/notifications')->assertJsonCount(0, 'notifications');
    expect($other->unreadNotifications()->count())->toBe(1);
});
