<?php

use App\GameProtocol\Proto\Green\Taiko\PlayResultDataRequest;
use App\GameProtocol\Proto\Green\Taiko\PlayResultDataRequest\StageData;
use App\GameProtocol\Proto\Green\Taiko\PlayResultRequest;
use App\Models\ExtraChart;
use App\Models\ExtraChartBest;
use App\Models\ExtraChartPlayResult;
use App\Models\ExtraSong;
use App\Models\GameCard;
use App\Models\Player;
use App\Models\PlayerVersionStats;
use App\Models\SongBest;
use App\Models\SongPlayResult;
use App\Models\User;
use App\Services\ExtraRankAggregateService;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    config()->set('taiko_green.zucchini_api_token_hashes', [hash('sha256', 'official-token')]);
});

it('stores an authenticated custom stage only in Extra tables', function (): void {
    $player = extra_player();
    $hash = str_repeat('a', 64);

    post_extra_play($player, 6689, 4, 800000, 3, 0, $hash, 'Custom Oni', '2026-07-11 10:00:00')
        ->assertSuccessful();

    expect(ExtraChartPlayResult::query()->count())->toBe(1)
        ->and(ExtraChartBest::query()->firstOrFail()->best_score)->toBe(800000)
        ->and(ExtraChartBest::query()->firstOrFail()->best_crown)->toBe(2)
        ->and(SongPlayResult::query()->count())->toBe(0)
        ->and(SongBest::query()->count())->toBe(0);
});

it('stores independent Extra results for both carded players', function (): void {
    $left = extra_player('30800000000000000001');
    $right = extra_player('30800000000000000002');
    $hash = str_repeat('9', 64);

    post_extra_play($left, 6689, 4, 810000, 3, 1, $hash, 'Two Player', '2026-07-11 10:00:00', true, false)
        ->assertSuccessful();
    post_extra_play($right, 6689, 4, 920000, 2, 0, $hash, 'Two Player', '2026-07-11 10:00:00', true, true)
        ->assertSuccessful();

    expect(ExtraChartPlayResult::query()->count())->toBe(2)
        ->and(ExtraChartPlayResult::query()->where('baid', $left->baid)->where('is_right', false)->exists())->toBeTrue()
        ->and(ExtraChartPlayResult::query()->where('baid', $right->baid)->where('is_right', true)->exists())->toBeTrue()
        ->and(ExtraChartBest::query()->where('baid', $left->baid)->value('best_score'))->toBe(810000)
        ->and(ExtraChartBest::query()->where('baid', $right->baid)->value('best_score'))->toBe(920000);
});

it('keeps unregistered bests personal and applies registration retroactively', function (): void {
    $player = extra_player();
    $hash = str_repeat('b', 64);

    post_extra_play($player, 6689, 4, 900000, 4, 0, $hash, 'Candidate', '2026-07-11 10:00:00')
        ->assertSuccessful();

    expect(PlayerVersionStats::query()->where('game_version', 'extra')->firstOrFail()->total_score)->toBe(0);

    $song = ExtraSong::query()->create(['title' => 'Approved', 'is_ranked' => true]);
    $chart = ExtraChart::query()->where('sha256', $hash)->firstOrFail();
    $chart->update(['extra_song_id' => $song->id, 'difficulty' => 4]);
    app(ExtraRankAggregateService::class)->recompute($player);

    expect(PlayerVersionStats::query()->where('game_version', 'extra')->firstOrFail()->total_score)->toBe(900000)
        ->and(PlayerVersionStats::query()->where('game_version', 'extra')->firstOrFail()->ranked_song_count)->toBe(1);
});

it('marks whether Extra board scores count for leaderboards', function (): void {
    $player = extra_player();
    $hash = str_repeat('f', 64);

    post_extra_play($player, 6689, 4, 900000, 4, 0, $hash, 'Candidate', '2026-07-11 10:00:00')
        ->assertSuccessful();

    $this->actingAs($player->user)
        ->get("/extra/users/{$player->user_id}/board")
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('recentPlays.0.counts_for_leaderboard', false)
            ->where('bestPerformances.0.counts_for_leaderboard', false));

    $song = ExtraSong::query()->create(['title' => 'Approved', 'is_ranked' => true]);
    ExtraChart::query()->where('sha256', $hash)->update(['extra_song_id' => $song->id, 'difficulty' => 4]);

    $this->get("/extra/users/{$player->user_id}/board")
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('recentPlays.0.counts_for_leaderboard', true)
            ->where('bestPerformances.0.counts_for_leaderboard', true));
});

it('serves hash keyed bests only to the matching card', function (): void {
    $player = extra_player();
    $hash = str_repeat('c', 64);
    post_extra_play($player, 6689, 3, 765432, 2, 1, $hash, 'Custom Hard', '2026-07-11 10:00:00');

    $this->withToken('official-token')
        ->postJson('/api/zucchini/extra/bests', ['access_code' => $player->card->access_code])
        ->assertSuccessful()
        ->assertJsonPath('data.0.sha256', $hash)
        ->assertJsonPath('data.0.best_score', 765432)
        ->assertJsonPath('data.0.best_crown', 1);

    $this->withHeader('Authorization', '')
        ->postJson('/api/zucchini/extra/bests', ['access_code' => $player->card->access_code])
        ->assertUnauthorized();
});

it('ignores unsigned chart metadata and preserves stock behavior', function (): void {
    $player = extra_player();
    $request = extra_play_request($player, 100, 3, 700000, 2, 0, '2026-07-11 10:00:00');

    $this->call('POST', '/v11r01/chassis/playresult.php', [], [], [], [
        'CONTENT_TYPE' => 'application/protobuf',
        'HTTP_X_TAIKONLINE_EXTRA_MAP' => extra_map(100, 3, str_repeat('d', 64), 'Spoof'),
    ], $request->serializeToString())->assertSuccessful();

    expect(SongPlayResult::query()->count())->toBe(1)
        ->and(SongBest::query()->count())->toBe(1)
        ->and(ExtraChartPlayResult::query()->count())->toBe(0);
});

it('exposes registered charts through the Extra website scope', function (): void {
    $player = extra_player();
    $hash = str_repeat('e', 64);
    post_extra_play($player, 6689, 4, 880000, 1, 0, $hash, 'Public Extra', '2026-07-11 10:00:00');
    $song = ExtraSong::query()->create(['title' => 'Public Extra', 'is_ranked' => true]);
    ExtraChart::query()->where('sha256', $hash)->update(['extra_song_id' => $song->id, 'difficulty' => 4]);
    app(ExtraRankAggregateService::class)->recompute($player);

    $this->get('/extra/rankings')->assertSuccessful()->assertInertia(fn (Assert $page) => $page
        ->component('Rankings')->where('gameVersion.value', 'extra')->has('entries', 1));
    $this->get('/extra/songs')->assertSuccessful()->assertInertia(fn (Assert $page) => $page
        ->component('Songs')->where('gameVersion.value', 'extra')->has('songs.data', 1));
    $this->get("/extra/songs/{$song->id}")->assertSuccessful()->assertInertia(fn (Assert $page) => $page
        ->component('SongDetail')->where('song.title', 'Public Extra')->has('difficulties', 1));
});

function extra_player(string $accessCode = '30800000000000000001'): Player
{
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);
    GameCard::query()->create([
        'access_code' => $accessCode,
        'baid' => $player->baid,
    ]);

    return $player->fresh('card');
}

function post_extra_play(Player $player, int $uid, int $level, int $score, int $ok, int $miss, string $hash, string $title, string $playedAt, bool $isTwoPlayers = false, bool $isRight = true): TestResponse
{
    $request = extra_play_request($player, $uid, $level, $score, $ok, $miss, $playedAt, $isTwoPlayers, $isRight);

    return test()->call('POST', '/v11r01/chassis/playresult.php', [], [], [], [
        'CONTENT_TYPE' => 'application/protobuf',
        'HTTP_AUTHORIZATION' => 'Bearer official-token',
        'HTTP_X_TAIKONLINE_EXTRA_MAP' => extra_map($uid, $level, $hash, $title),
    ], $request->serializeToString());
}

function extra_map(int $uid, int $level, string $hash, string $title): string
{
    $json = json_encode(['v' => 1, 'charts' => [[
        'uid' => $uid, 'level' => $level, 'sha256' => $hash,
        'title' => $title, 'source_id' => 'ese_test',
    ]]], JSON_THROW_ON_ERROR);

    return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
}

function extra_play_request(Player $player, int $songNo, int $level, int $score, int $ok, int $miss, string $playedAt, bool $isTwoPlayers = false, bool $isRight = true): PlayResultRequest
{
    $stage = (new StageData)
        ->setSongNo($songNo)->setLevel($level)->setPlayResult(2)
        ->setPlayScore($score)->setOkCnt($ok)->setNgCnt($miss);
    $data = (new PlayResultDataRequest)
        ->setBaid($player->baid)->setChassisId('chassis')->setShopId('shop')
        ->setPlayDatetime($playedAt)->setIsRight($isRight)->setCardType(1)
        ->setIsTwoPlayers($isTwoPlayers)->setAryStageInfo([$stage])->setReserved('')
        ->setDifficultyPlayedCourse($level)->setDifficultyPlayedStar(8);

    return (new PlayResultRequest)
        ->setBaidConf($player->baid)->setChassisIdConf('chassis')
        ->setShopIdConf('shop')->setPlayDatetimeConf($playedAt)
        ->setPlayresultData(gzencode($data->serializeToString()));
}
