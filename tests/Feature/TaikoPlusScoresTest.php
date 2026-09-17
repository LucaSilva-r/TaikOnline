<?php

use App\Models\ExtraChart;
use App\Models\ExtraChartBest;
use App\Models\ExtraChartPlayResult;
use App\Models\GameCard;
use App\Models\Player;
use App\Models\SongBest;
use App\Models\User;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    config()->set('taiko_green.zucchini_api_token_hashes', [hash('sha256', 'official-token')]);
});

it('stores a Taiko+ stage in the Extra tables', function (): void {
    $player = taikoplus_player();

    post_taikoplus_score($player)->assertSuccessful()
        ->assertJson(['result' => 'ok', 'stages' => 1]);

    $chart = ExtraChart::query()->firstOrFail();
    expect($chart->source_kind)->toBe('tja')
        ->and($chart->source_sha256)->toBe(str_repeat('b', 64))
        ->and($chart->observed_title)->toBe('Imported Song')
        ->and(ExtraChartPlayResult::query()->firstOrFail()->client)->toBe('recomp')
        ->and(ExtraChartBest::query()->firstOrFail()->best_score)->toBe(800000)
        // No misses, some OKs: gold.
        ->and(ExtraChartBest::query()->firstOrFail()->best_crown)->toBe(2);
});

it('treats a repeated play id as already stored', function (): void {
    $player = taikoplus_player();

    post_taikoplus_score($player)->assertSuccessful();
    post_taikoplus_score($player, ['score' => 999999])->assertSuccessful()
        ->assertJson(['result' => 'duplicate']);

    expect(ExtraChartPlayResult::query()->count())->toBe(1)
        ->and(ExtraChartBest::query()->firstOrFail()->best_score)->toBe(800000);
});

it('never lowers a best score or crown', function (): void {
    $player = taikoplus_player();

    post_taikoplus_score($player)->assertSuccessful();
    post_taikoplus_score($player, ['score' => 400000, 'ok' => 9, 'miss' => 9], '2')->assertSuccessful();

    $best = ExtraChartBest::query()->firstOrFail();
    expect($best->best_score)->toBe(800000)->and($best->best_crown)->toBe(2);
});

it('records a failed run without awarding a crown', function (): void {
    $player = taikoplus_player();

    post_taikoplus_score($player, ['ok' => 0, 'miss' => 0, 'cleared' => false, 'gauge' => 4000])
        ->assertSuccessful();

    expect(ExtraChartBest::query()->firstOrFail()->best_crown)->toBe(0)
        ->and(ExtraChartPlayResult::query()->firstOrFail()->play_result)->toBe(0);
});

it('rejects an unknown access code and a missing token', function (): void {
    $player = taikoplus_player();

    post_taikoplus_score($player, [], '1', '30800000000000009999')->assertNotFound();

    // withHeaders() persists on the test instance, so drop the bearer first.
    test()->flushHeaders()->postJson('/api/taikoplus/scores', taikoplus_payload())->assertUnauthorized();
    expect(ExtraChartPlayResult::query()->count())->toBe(0);
});

it('returns both boards of bests and pages the Extra one', function (): void {
    $player = taikoplus_player();
    post_taikoplus_score($player)->assertSuccessful();
    SongBest::query()->create([
        'baid' => $player->baid,
        'game_version' => 'green',
        'song_no' => 875,
        'level' => 4,
        'is_shin' => false,
        'best_score' => 517920,
        'best_score_rank' => 5,
        'best_play_result' => 2,
        'best_crown' => 2,
    ]);

    $body = test()->withHeaders(['Authorization' => 'Bearer official-token'])
        ->post('/api/taikoplus/bests', ['access_code' => '30800000000000000001'])
        ->assertSuccessful()
        ->getContent();

    $lines = array_values(array_filter(explode("\n", $body)));
    expect($lines[0])->toBe('e,'.str_repeat('a', 64).',800000,2')
        ->and($lines[1])->toBe('s,875,4,517920,2')
        ->and(end($lines))->toBe('next_cursor=');
});

it('returns an empty page for an unknown card', function (): void {
    $body = test()->withHeaders(['Authorization' => 'Bearer official-token'])
        ->post('/api/taikoplus/bests', ['access_code' => '30800000000000009999'])
        ->assertSuccessful()
        ->getContent();

    expect(trim($body))->toBe('next_cursor=');
});

function taikoplus_player(string $accessCode = '30800000000000000001'): Player
{
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);
    GameCard::query()->create(['access_code' => $accessCode, 'baid' => $player->baid]);

    return $player->fresh('card');
}

/**
 * @param  array<string, mixed>  $stage
 * @return array<string, mixed>
 */
function taikoplus_payload(array $stage = [], string $playId = '1', string $accessCode = '30800000000000000001'): array
{
    return [
        'v' => 1,
        'access_code' => $accessCode,
        'play_id' => str_pad($playId, 32, '0', STR_PAD_LEFT),
        'client' => 'recomp',
        'stages' => [array_merge([
            'chart_key' => str_repeat('a', 64),
            'source_sha256' => str_repeat('b', 64),
            'source_kind' => 'tja',
            'title' => 'Imported Song',
            'source_id' => 'tc0123456789ab',
            'level' => 4,
            'star_level' => 8,
            'score' => 800000,
            'good' => 400,
            'ok' => 20,
            'miss' => 0,
            'drumroll' => 12,
            'combo' => 300,
            'hits' => 432,
            'gauge' => 10000,
            'cleared' => true,
        ], $stage)],
    ];
}

/**
 * @param  array<string, mixed>  $stage
 */
function post_taikoplus_score(Player $player, array $stage = [], string $playId = '1', string $accessCode = '30800000000000000001'): TestResponse
{
    return test()->withHeaders(['Authorization' => 'Bearer official-token'])
        ->postJson('/api/taikoplus/scores', taikoplus_payload($stage, $playId, $accessCode));
}
