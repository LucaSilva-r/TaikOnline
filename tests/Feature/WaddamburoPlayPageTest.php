<?php

use App\Enums\SanctionType;
use App\Http\Controllers\WaddamburoPlayController;
use App\Models\Player;
use App\Models\Sanction;
use App\Models\User;
use App\Models\WdbChart;
use App\Models\WdbPlay;
use App\Services\WaddamburoSongs;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function play_page_player(?User $user = null): Player
{
    $user ??= User::factory()->create();

    return Player::query()->create(['user_id' => $user->id, 'mydon_name' => 'どん'])->fresh('user');
}

function play_page_play(Player $player, WdbChart $chart, int $score): WdbPlay
{
    return WdbPlay::query()->create([
        'id' => (string) Str::uuid(), 'baid' => $player->baid, 'wdb_chart_id' => $chart->id, 'mode' => 'normal',
        'course' => 3, 'score' => $score, 'great' => 100, 'good' => 0, 'miss' => 0, 'max_combo' => 100,
        'rolls' => 0, 'gauge' => 50, 'cleared' => true, 'scoring_version' => 4, 'engine_version' => 'test',
        'played_at' => now(), 'replay' => 'inputs', 'rescored_at' => now(),
    ]);
}

function play_page_chart(): WdbChart
{
    $chart = WdbChart::query()->create(['sha256' => str_repeat('a', 64), 'title' => 'Song', 'course' => 3, 'ranked_at' => now(), 'notes' => 'gz']);
    WaddamburoSongs::attach([['wdb_chart_id' => $chart->id, 'title' => 'Song', 'course' => 3]]);

    return $chart;
}

it('links the song board\'s scores to their plays, whose page everyone sees without the moderation panel', function (): void {
    $chart = play_page_chart();
    $player = play_page_player();
    $play = play_page_play($player, $chart, 900_000);
    $song = $chart->songs()->value('wdb_songs.id');

    $this->get("/waddamburo/songs/{$song}")->assertInertia(fn (Assert $page) => $page
        ->where('difficulties.0.entries.0.play_id', $play->id)->where('recentPlays.0.play_id', $play->id));
    $this->actingAs(User::factory()->create())->get("/waddamburo/plays/{$play->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('WaddamburoPlay')
            ->where('play.score', 900_000)->where('play.placement', 1)->where('player.user_id', $player->user_id)
            ->where('moderation', null)->where('inspection', null));
    $this->get("/green/plays/{$play->id}")->assertNotFound();
});

it('shows hidden and shadowbanned plays to staff only, with the engine\'s timeline', function (): void {
    $chart = play_page_chart();
    $cheater = play_page_player();
    $play = play_page_play($cheater, $chart, 1_000_000);
    $play->update(['timeline' => gzencode(json_encode([
        'windows' => ['great' => 25, 'good' => 75, 'miss' => 108], 'duration' => 5000,
        'notes' => [['t' => 1000, 'kind' => 'don', 'result' => 'great', 'offset' => -4], ['t' => 1500, 'kind' => 'ka', 'result' => 'good', 'offset' => 30]],
        'long_notes' => [['start' => 2000, 'end' => 3000, 'kind' => 'roll', 'required' => 0, 'hits' => [2000, 2100, 2200]]],
        'inputs' => [['t' => 995, 'action' => 0, 'hit' => 'note', 'note' => 0]], 'bars' => [], 'gogo' => [],
    ]))]);
    Sanction::query()->create(['baid' => $cheater->baid, 'type' => SanctionType::Shadowban, 'reason' => 'macro']);

    $this->actingAs(User::factory()->create())->get("/waddamburo/plays/{$play->id}")->assertNotFound();
    // The owner gets the replay player's data: the notes as judged and the pad each input hit, not what it hit.
    $this->actingAs($cheater->user)->get("/waddamburo/plays/{$play->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('inspection', null)
            ->loadDeferredProps(fn (Assert $reload) => $reload->has('replay.notes', 2)->has('replay.long_notes', 1)
                ->where('replay.inputs', [['t' => 995, 'action' => 0]])));
    $this->actingAs(User::factory()->moderator()->create())->get("/waddamburo/plays/{$play->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('moderation.baid', (int) $cheater->baid)->where('moderation.sanctions', ['shadowban'])->where('moderation.can_act', true)
            ->missing('inspection')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('inspection.notes.1.offset', 30)
                ->where('stats', ['mean_offset' => 13, 'early' => 1, 'late' => 1, 'roll_speed' => ['average' => 10, 'peak' => 10]])));
});

it('gives staff a way from a player\'s board into their moderation page', function (): void {
    $player = play_page_player();

    $this->actingAs(User::factory()->create())->get("/waddamburo/users/{$player->user_id}/board")
        ->assertInertia(fn (Assert $page) => $page->where('moderationBaid', null));
    $this->actingAs(User::factory()->moderator()->create())->get("/green/users/{$player->user_id}/board")
        ->assertInertia(fn (Assert $page) => $page->where('moderationBaid', (int) $player->baid));
});

it('keeps the public numbers to timing and drumroll speed', function (): void {
    expect(WaddamburoPlayController::publicStats(null))->toBeNull()
        ->and(WaddamburoPlayController::publicStats(['notes' => [['result' => 'miss']], 'long_notes' => [['hits' => [100]]]]))
        ->toBe(['mean_offset' => null, 'early' => 0, 'late' => 0, 'roll_speed' => null]);
});
