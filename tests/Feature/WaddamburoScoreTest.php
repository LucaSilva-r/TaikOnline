<?php

use App\Http\Controllers\WaddamburoScoreController;
use App\Models\Player;
use App\Models\User;
use App\Models\WdbChart;
use App\Models\WdbPlay;
use Illuminate\Support\Str;

function wdb_score_player(string $name): Player
{
    $user = User::factory()->create(['name' => $name]);

    return Player::query()->create(['user_id' => $user->id, 'mydon_name' => 'どん'])->fresh('user');
}

function wdb_score_play(Player $player, WdbChart $chart, int $score, string $at, string $replay = 'inputs'): WdbPlay
{
    return WdbPlay::query()->create([
        'id' => (string) Str::uuid(), 'baid' => $player->baid, 'wdb_chart_id' => $chart->id, 'mode' => 'normal',
        'course' => 3, 'score' => $score, 'great' => 100, 'good' => 0, 'miss' => 0, 'max_combo' => 100,
        'rolls' => 0, 'gauge' => 50, 'cleared' => true, 'scoring_version' => 1, 'engine_version' => 'test',
        'played_at' => $at, 'replay' => $replay, 'audio_offset_ms' => 10, 'input_offset_ms' => -5,
    ]);
}

it('ranks each player once by their best play, the earlier play winning a tie', function (): void {
    $chart = WdbChart::query()->create(['sha256' => str_repeat('a', 64), 'title' => 'Song', 'course' => 3]);
    [$ann, $bob, $cid] = [wdb_score_player('Ann'), wdb_score_player('Bob'), wdb_score_player('Cid')];
    wdb_score_play($ann, $chart, 900_000, '2026-10-01T10:00:00Z');
    $annBest = wdb_score_play($ann, $chart, 950_000, '2026-10-02T10:00:00Z');
    wdb_score_play($bob, $chart, 950_000, '2026-10-03T10:00:00Z'); // ties Ann, later
    wdb_score_play($cid, $chart, 800_000, '2026-10-01T10:00:00Z');
    $token = $cid->user->createToken('test', ['wdb'])->plainTextToken;

    $this->withToken($token)->getJson('/api/wdb/charts/'.str_repeat('a', 64).'/scores')->assertOk()
        ->assertJsonCount(3, 'scores')
        ->assertJsonPath('scores.0.play_id', $annBest->id)
        ->assertJsonPath('scores.0.name', 'Ann')
        ->assertJsonPath('scores.0.crown', 3)
        ->assertJsonPath('scores.1.name', 'Bob')
        ->assertJsonPath('scores.2.rank', 3)
        ->assertJsonPath('mine.name', 'Cid')
        ->assertJsonPath('mine.rank', 3);
    $this->withToken($token)->getJson('/api/wdb/charts/'.str_repeat('b', 64).'/scores')->assertOk()
        ->assertExactJson(['scores' => [], 'mine' => null]);
});

it('places the player\'s own best below the shown leaderboard', function (): void {
    $chart = WdbChart::query()->create(['sha256' => str_repeat('c', 64), 'course' => 3]);
    foreach (range(1, WaddamburoScoreController::LIMIT) as $index) {
        wdb_score_play(wdb_score_player("P{$index}"), $chart, 1_000_000 - $index, '2026-10-01T10:00:00Z');
    }
    $me = wdb_score_player('Me');
    wdb_score_play($me, $chart, 10, '2026-10-01T10:00:00Z');

    $this->withToken($me->user->createToken('test', ['wdb'])->plainTextToken)
        ->getJson('/api/wdb/charts/'.str_repeat('c', 64).'/scores')
        ->assertJsonCount(WaddamburoScoreController::LIMIT, 'scores')
        ->assertJsonPath('mine.rank', WaddamburoScoreController::LIMIT + 1);
});

it('lists the player\'s own plays best first and hands out any play\'s replay', function (): void {
    $chart = WdbChart::query()->create(['sha256' => str_repeat('d', 64), 'course' => 3]);
    $me = wdb_score_player('Me');
    $other = wdb_score_player('Other');
    wdb_score_play($me, $chart, 500_000, '2026-10-01T10:00:00Z');
    wdb_score_play($me, $chart, 700_000, '2026-10-02T10:00:00Z');
    $theirs = wdb_score_play($other, $chart, 999_000, '2026-10-01T10:00:00Z', replay: 'their inputs');
    $token = $me->user->createToken('test', ['wdb'])->plainTextToken;

    $this->withToken($token)->getJson('/api/wdb/charts/'.str_repeat('d', 64).'/scores/mine')->assertOk()
        ->assertJsonCount(2, 'scores')
        ->assertJsonPath('scores.0.score', 700_000)
        ->assertJsonMissingPath('scores.0.replay');
    $this->withToken($token)->getJson("/api/wdb/plays/{$theirs->id}/replay")->assertOk()
        ->assertJsonPath('chart_sha256', str_repeat('d', 64))
        ->assertJsonPath('name', 'Other')
        ->assertJsonPath('player.baid', (int) $other->baid)
        ->assertJsonCount(5, 'player.look.costume')
        ->assertJsonPath('input_offset_ms', -5)
        ->assertJsonPath('replay', base64_encode('their inputs'));
    $this->withToken($token)->getJson('/api/wdb/plays/'.Str::uuid().'/replay')->assertNotFound();
    app('auth')->forgetGuards();
    $this->withoutToken()->getJson("/api/wdb/plays/{$theirs->id}/replay")->assertUnauthorized();
});

it('keeps 真打 plays on their own board, with their options and seed', function (): void {
    $chart = WdbChart::query()->create(['sha256' => str_repeat('e', 64), 'course' => 3]);
    $ann = wdb_score_player('Ann');
    wdb_score_play($ann, $chart, 800_000, '2026-10-01T10:00:00Z');
    wdb_score_play($ann, $chart, 990_000, '2026-10-02T10:00:00Z')->forceFill(['options' => 2 | 256, 'seed' => 42])->save();
    $token = $ann->user->createToken('test', ['wdb'])->plainTextToken;
    $url = '/api/wdb/charts/'.str_repeat('e', 64).'/scores';

    $this->withToken($token)->getJson($url)->assertOk()
        ->assertJsonCount(1, 'scores')
        ->assertJsonPath('scores.0.score', 800_000)
        ->assertJsonPath('scores.0.options', 0);
    $this->withToken($token)->getJson($url.'?shinuchi=1')->assertOk()
        ->assertJsonCount(1, 'scores')
        ->assertJsonPath('scores.0.score', 990_000)
        ->assertJsonPath('scores.0.options', 258)
        ->assertJsonPath('scores.0.seed', 42);
});

it('names a play\'s options as the game does', function (): void {
    expect(WdbPlay::optionLabels(0))->toBe([])
        ->and(WdbPlay::optionLabels((3 << 9) | 4 | 32 | 256 | 2))->toBe(['1.3x', 'Hidden', 'Chaos', 'Shin-uchi'])
        ->and(WdbPlay::optionLabels(8 | 64 | 128))->toBe(['3.0x', 'Reversed', 'Random']);
});
