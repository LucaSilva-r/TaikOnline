<?php

use App\Enums\PlayReportReason;
use App\Enums\SanctionType;
use App\Models\ModerationAction;
use App\Models\Player;
use App\Models\PlayReport;
use App\Models\Sanction;
use App\Models\User;
use App\Models\WdbCabinet;
use App\Models\WdbChart;
use App\Models\WdbPlay;
use App\Services\ModerationService;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function report_player(): Player
{
    return Player::query()->create(['user_id' => User::factory()->create()->id, 'mydon_name' => 'どん'])->fresh('user');
}

function report_play(Player $player, ?WdbChart $chart = null): WdbPlay
{
    $chart ??= WdbChart::query()->firstOrCreate(['sha256' => str_repeat('a', 64)], ['title' => 'Song', 'course' => 3]);

    return WdbPlay::query()->create([
        'id' => (string) Str::uuid(), 'baid' => $player->baid, 'wdb_chart_id' => $chart->id, 'mode' => 'normal',
        'course' => 3, 'score' => 1_000_000, 'great' => 100, 'good' => 0, 'miss' => 0, 'max_combo' => 100,
        'rolls' => 0, 'gauge' => 50, 'cleared' => true, 'scoring_version' => 4, 'engine_version' => 'test',
        'played_at' => now(), 'replay' => 'inputs', 'rescored_at' => now(),
    ]);
}

it('takes one report per play from a logged-in player watching someone else\'s replay', function (): void {
    [$cheater, $reporter] = [report_player(), report_player()];
    $play = report_play($cheater);
    $token = $reporter->user->createToken('test', ['wdb'])->plainTextToken;

    $this->withToken($token)->postJson("/api/wdb/plays/{$play->id}/report", ['comment' => 'Perfectly even rolls'])->assertCreated();
    $this->withToken($token)->postJson("/api/wdb/plays/{$play->id}/report")->assertOk()
        ->assertJsonPath('message', 'You already reported this play.');
    expect(PlayReport::query()->sole())->reason->value->toBe('cheating')->comment->toBe('Perfectly even rolls')->reporter_id->toBe($reporter->user_id);

    app('auth')->forgetGuards();
    $this->withToken($cheater->user->createToken('test', ['wdb'])->plainTextToken)
        ->postJson('/api/wdb/plays/'.report_play($reporter)->id.'/report', ['reason' => 'bogus'])->assertJsonValidationErrors('reason');
    $this->postJson("/api/wdb/plays/{$play->id}/report")->assertStatus(422); // the cheater's own play

    [, $cabinetToken] = WdbCabinet::issue('Cab', null);
    app('auth')->forgetGuards();
    $this->withToken($cabinetToken)->postJson("/api/wdb/plays/{$play->id}/report")->assertForbidden();
});

it('refuses blocked reporters and slows down spam', function (): void {
    $reporter = report_player();
    $token = $reporter->user->createToken('test', ['wdb'])->plainTextToken;
    $plays = collect(range(1, 6))->map(fn () => report_play(report_player()));

    $plays->take(5)->each(fn (WdbPlay $play) => $this->withToken($token)->postJson("/api/wdb/plays/{$play->id}/report")->assertCreated());
    $this->withToken($token)->postJson("/api/wdb/plays/{$plays->last()->id}/report")->assertTooManyRequests();

    $this->travel(11)->minutes();
    Sanction::query()->create(['baid' => $reporter->baid, 'type' => SanctionType::ReportBlock, 'reason' => 'spam']);
    $this->withToken($token)->postJson("/api/wdb/plays/{$plays->last()->id}/report")->assertForbidden();
});

it('reports from the play page', function (): void {
    [$cheater, $reporter] = [report_player(), report_player()];
    $play = report_play($cheater);

    $this->actingAs($reporter->user)->get("/waddamburo/plays/{$play->id}")
        ->assertInertia(fn (Assert $page) => $page->where('report.reported', false)->has('report.reasons', 3));
    $this->actingAs($reporter->user)->post("/waddamburo/plays/{$play->id}/report", ['reason' => 'offensive', 'comment' => 'name'])->assertRedirect();
    $this->actingAs($reporter->user)->get("/waddamburo/plays/{$play->id}")->assertInertia(fn (Assert $page) => $page->where('report.reported', true));
    $this->actingAs($cheater->user)->get("/waddamburo/plays/{$play->id}")->assertInertia(fn (Assert $page) => $page->where('report', null));
    expect(PlayReport::query()->sole()->reason->value)->toBe('offensive');
});

it('queues reports for moderators, who close them by dismissing, hiding the play or sanctioning the player', function (): void {
    $moderator = User::factory()->moderator()->create();
    [$cheater, $other, $first, $second] = [report_player(), report_player(), report_player(), report_player()];
    [$cheat, $fine, $later] = [report_play($cheater), report_play($other), report_play($cheater)];
    $moderation = app(ModerationService::class);
    $moderation->report($first->user, $cheat, PlayReportReason::Cheating, null);
    $moderation->report($second->user, $cheat, PlayReportReason::Cheating, 'macro');
    $moderation->report($first->user, $fine, PlayReportReason::Other, null);
    $moderation->report($first->user, $later, PlayReportReason::Cheating, null);

    $this->actingAs(User::factory()->create())->get('/waddamburo/mod/reports')->assertForbidden();
    $this->actingAs($moderator)->get('/waddamburo/mod/reports')->assertInertia(fn (Assert $page) => $page
        ->component('mod/Reports')->has('plays', 3)->where('plays.0.id', $cheat->id)->has('plays.0.reports', 2)
        ->where('plays.0.reports.0.reporter.filed', 3));

    $this->actingAs($moderator)->patch("/waddamburo/mod/plays/{$fine->id}/dismiss-reports")->assertRedirect();
    $this->actingAs($moderator)->patch("/waddamburo/mod/plays/{$cheat->id}/hide", ['reason' => 'macro'])->assertRedirect();
    $this->actingAs($moderator)->post("/waddamburo/mod/players/{$cheater->baid}/sanctions", ['type' => 'shadowban', 'reason' => 'macro'])->assertRedirect();

    expect(PlayReport::query()->open()->count())->toBe(0)
        ->and(PlayReport::query()->pluck('resolution', 'wdb_play_id')->all())
        ->toBe([$cheat->id => 'play_hidden', $fine->id => 'dismissed', $later->id => 'player_sanctioned'])
        ->and(ModerationAction::query()->where('action', 'dismiss_reports')->value('wdb_play_id'))->toBe($fine->id);
    $this->actingAs($moderator)->get('/waddamburo/mod/reports')->assertInertia(fn (Assert $page) => $page->has('plays', 0));
});
