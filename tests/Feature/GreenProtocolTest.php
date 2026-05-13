<?php

use App\Enums\SongGenre;
use App\Enums\SongPartsSet;
use App\Enums\SongWai2PartsSet;
use App\GameProtocol\Green\Proto\Taiko\BAIDRequest;
use App\GameProtocol\Green\Proto\Taiko\BAIDResponse;
use App\GameProtocol\Green\Proto\Taiko\ChallengeCompeRequest;
use App\GameProtocol\Green\Proto\Taiko\ChallengeCompeResponse;
use App\GameProtocol\Green\Proto\Taiko\GetfolderRequest;
use App\GameProtocol\Green\Proto\Taiko\GetfolderResponse;
use App\GameProtocol\Green\Proto\Taiko\GetghostdataRequest;
use App\GameProtocol\Green\Proto\Taiko\GetghostdataResponse;
use App\GameProtocol\Green\Proto\Taiko\GetghostscoreRequest;
use App\GameProtocol\Green\Proto\Taiko\GetghostscoreResponse;
use App\GameProtocol\Green\Proto\Taiko\GettelopRequest;
use App\GameProtocol\Green\Proto\Taiko\GettelopResponse;
use App\GameProtocol\Green\Proto\Taiko\InitialdatacheckRequest;
use App\GameProtocol\Green\Proto\Taiko\InitialdatacheckResponse;
use App\GameProtocol\Green\Proto\Taiko\PlayResultDataRequest;
use App\GameProtocol\Green\Proto\Taiko\PlayResultDataRequest\StageData;
use App\GameProtocol\Green\Proto\Taiko\PlayResultDataRequest\StageData\GhostStageData;
use App\GameProtocol\Green\Proto\Taiko\PlayResultDataRequest\StageData\GhostStageData\GhostStageSectionData;
use App\GameProtocol\Green\Proto\Taiko\PlayResultRequest;
use App\GameProtocol\Green\Proto\Taiko\PlayResultResponse;
use App\GameProtocol\Green\Proto\Taiko\RecommendRequest;
use App\GameProtocol\Green\Proto\Taiko\RecommendResponse;
use App\GameProtocol\Green\Proto\Taiko\RewardcardcheckRequest;
use App\GameProtocol\Green\Proto\Taiko\RewardcardcheckResponse;
use App\GameProtocol\Green\Proto\Taiko\RewardexecutionRequest;
use App\GameProtocol\Green\Proto\Taiko\RewardexecutionResponse;
use App\GameProtocol\Green\Proto\Taiko\SelfBestRequest;
use App\GameProtocol\Green\Proto\Taiko\SelfBestResponse;
use App\GameProtocol\Green\Proto\Taiko\TournamentcheckRequest;
use App\GameProtocol\Green\Proto\Taiko\TournamentcheckResponse;
use App\GameProtocol\Green\Proto\Taiko\UserDataRequest;
use App\GameProtocol\Green\Proto\Taiko\UserDataResponse;
use App\GameProtocol\Green\Proto\VsInterface\StartupAuthRequest;
use App\GameProtocol\Green\Proto\VsInterface\StartupAuthRequest\OperationData as StartupOperationData;
use App\GameProtocol\Green\Proto\VsInterface\StartupAuthResponse;
use App\Models\Cabinet;
use App\Models\GameCard;
use App\Models\Player;
use App\Models\Song;
use App\Models\SongBest;
use App\Models\SongPlayResult;
use Google\Protobuf\Internal\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

it('responds to allnet power on with form data', function (): void {
    $payload = base64_encode(gzcompress(http_build_query(['token' => 'abc'])));

    $this->call('POST', '/sys/servlet/PowerOn', [], [], [], ['CONTENT_TYPE' => 'text/plain'], $payload)
        ->assertOk()
        ->assertSee('stat=1', false)
        ->assertSee('uri=127.0.0.1', false)
        ->assertSee('host=127.0.0.1', false)
        ->assertSee('token=123', false);
});

it('responds to mucha board auth with green service urls', function (): void {
    $this->post('/mucha_front/boardauth.do', ['placeId' => 'JPN9999'])
        ->assertOk()
        ->assertSee('RESULTS=001', false)
        ->assertSee('CHARGE_URL=https://127.0.0.1:54430/charge/', false)
        ->assertSee('FILE_URL=https://127.0.0.1:54430/file/', false)
        ->assertSee('FORCE_BOOT=0', false)
        ->assertSee('PLACE_ID=JPN9999', false)
        ->assertSee('CONSUME_TOKEN=0', false);
});

it('responds to mucha update check like the green reference server', function (): void {
    config()->set('taiko_green.mucha_force_update', false);

    $this->post('/mucha_front/updatacheck.do', ['gameVer' => 'S1210JPN08.18'])
        ->assertOk()
        ->assertSee('RESULTS=001', false)
        ->assertSee('UPDATE_URL_1=https://127.0.0.1:54430/updUrl1/', false)
        ->assertSee('UPDATE_SIZE_1=20', false)
        ->assertSee('CHECK_SIZE_1=20', false)
        ->assertSee('USER_ID=1', false)
        ->assertSee('PASSWORD=1', false)
        ->assertSee('EXE_VER=S1210JPN08.18', false);
});

it('mirrors startup operation data', function (): void {
    Cabinet::query()->create(['serial' => 'chassis']);

    $request = (new StartupAuthRequest)
        ->setChassisId('chassis')
        ->setHddVer(1113)
        ->setShopId('shop')
        ->setAryOperationInfo([
            (new StartupOperationData)->setKeyData(10)->setValueData('abc'),
        ]);

    $response = post_protobuf('/v01r00/chassis/startupauth.php', $request, StartupAuthResponse::class);

    expect($response->getResult())->toBe(1)
        ->and($response->getAryMovieInfo()[0]->getMovieId())->toBe(154)
        ->and($response->getAryMovieInfo()[0]->getEnableDays())->toBe(9999)
        ->and($response->getAryOperationInfo()[0]->getKeyData())->toBe(10)
        ->and($response->getAryOperationInfo()[0]->getValueData())->toBe('abc');
});

it('does not advertise green startup movies to blue cabinets', function (): void {
    $request = (new StartupAuthRequest)
        ->setChassisId('chassis')
        ->setHddVer(1010)
        ->setShopId('shop')
        ->setAryOperationInfo([
            (new StartupOperationData)->setKeyData(10)->setValueData('abc'),
        ]);

    $response = post_protobuf('/v01r00/chassis/startupauth.php', $request, StartupAuthResponse::class);

    expect($response->getResult())->toBe(1)
        ->and(iterator_to_array($response->getAryMovieInfo()))->toBe([])
        ->and($response->getAryOperationInfo()[0]->getKeyData())->toBe(10)
        ->and($response->getAryOperationInfo()[0]->getValueData())->toBe('abc');
});

it('returns default released songs during initial data check', function (): void {
    create_song('green', 1);
    create_song('green', 8);
    create_song('blue', 9);

    $request = (new InitialdatacheckRequest)
        ->setChassisId('chassis')
        ->setShopId('shop');

    $response = post_protobuf('/v11r01/chassis/initialdatacheck.php', $request, InitialdatacheckResponse::class);

    expect($response->getResult())->toBe(1)
        ->and($response->getSongHashVer())->toBe(1)
        ->and(ord($response->getHashDefaultSongFlg()[0]))->toBe(129)
        ->and(ord($response->getHashDefaultSongFlg()[1]))->toBe(0);
});

it('uses the blue catalog for blue power on route revisions', function (): void {
    create_song('green', 1);
    create_song('blue', 9);

    $request = (new InitialdatacheckRequest)
        ->setChassisId('chassis')
        ->setShopId('shop');

    foreach (['v10r01', 'v10r02', 'v10r03'] as $routeVersion) {
        $response = post_protobuf_raw("/{$routeVersion}/chassis/initialdatacheck.php", $request);
        $body = $response->getContent();

        expect(bin2hex(substr($body, 0, 7)))->toBe('080110011a8004')
            ->and(ord($body[7]))->toBe(0)
            ->and(ord($body[8]))->toBe(1)
            ->and(str_contains($body, hex2bin('7001')))->toBeTrue()
            ->and(str_contains($body, hex2bin('6801')))->toBeFalse();
    }
});

it('returns carded player released songs from the route catalog version', function (): void {
    config()->set('taiko_green.route_catalog_versions', [
        'v08r00' => 'ST-10100-1',
        'v11r01' => 'ST-11100-1',
    ]);

    create_song('green', 1);
    create_song('blue', 9);

    $player = Player::query()->create();
    $request = (new UserDataRequest)
        ->setBaid($player->baid)
        ->setChassisId('chassis')
        ->setShopId('shop');

    $greenResponse = post_protobuf('/v11r01/chassis/userdata.php', $request, UserDataResponse::class);
    $blueResponse = post_protobuf('/v08r00/chassis/userdata.php', $request, UserDataResponse::class);

    expect(ord($greenResponse->getHashReleaseSongFlg()[0]))->toBe(1)
        ->and(ord($greenResponse->getHashReleaseSongFlg()[1]))->toBe(0)
        ->and(ord($blueResponse->getHashReleaseSongFlg()[0]))->toBe(0)
        ->and(ord($blueResponse->getHashReleaseSongFlg()[1]))->toBe(1);
});

it('supports the reference green optional game endpoints', function (): void {
    $endpoints = [
        ['/v11r01/chassis/recommend.php', (new RecommendRequest)->setChassisId('chassis')->setShopId('shop'), RecommendResponse::class],
        ['/v11r01/chassis/tournamentcheck.php', (new TournamentcheckRequest)->setChassisId('chassis')->setShopId('shop'), TournamentcheckResponse::class],
        ['/v11r01/chassis/challengecompe.php', (new ChallengeCompeRequest)->setBaid(1)->setChassisId('chassis')->setShopId('shop'), ChallengeCompeResponse::class],
        ['/v11r01/chassis/rewardcardcheck.php', (new RewardcardcheckRequest)->setAccessCode('12345678901234567890')->setChassisId('chassis')->setShopId('shop'), RewardcardcheckResponse::class],
        ['/v11r01/chassis/rewardexecution.php', (new RewardexecutionRequest)->setBaid(1)->setChassisId('chassis')->setShopId('shop'), RewardexecutionResponse::class],
        ['/v11r01/chassis/gettelop.php', (new GettelopRequest)->setChassisId('chassis')->setShopId('shop')->setTelopId(1), GettelopResponse::class],
    ];

    foreach ($endpoints as [$uri, $request, $responseClass]) {
        $response = post_protobuf($uri, $request, $responseClass);

        expect($response->getResult())->toBe(1);
    }
});

it('returns event folder data for requested folders', function (): void {
    $request = (new GetfolderRequest)
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setFolderId([10, 11]);

    $response = post_protobuf('/v11r01/chassis/getfolder.php', $request, GetfolderResponse::class);

    expect($response->getResult())->toBe(1)
        ->and($response->getAryEventfolderData())->toHaveCount(2)
        ->and($response->getAryEventfolderData()[0]->getFolderId())->toBe(10)
        ->and(iterator_to_array($response->getAryEventfolderData()[0]->getSongNo()))->toBe([1, 2, 3]);
});

it('returns placeholder ghost battle player data', function (): void {
    $request = (new GetghostdataRequest)
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setBaid(1);

    $response = post_protobuf('/v11r01/chassis/getghostdata.php', $request, GetghostdataResponse::class);

    expect($response->getResult())->toBe(1)
        ->and($response->getGhostPerfData())->not->toBeNull()
        ->and($response->getGhostRecordData())->not->toBeNull()
        ->and($response->getReleaseInfoFlag())->toHaveLength(512)
        ->and($response->getPlayedSongFlag())->toHaveLength(512);
});

it('returns empty ghost battle score sections when no data exists', function (): void {
    $request = (new GetghostscoreRequest)
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setBaid(1)
        ->setSongNo(100)
        ->setLevel(3);

    $response = post_protobuf('/v11r01/chassis/getghostscore.php', $request, GetghostscoreResponse::class);

    expect($response->getResult())->toBe(1)
        ->and($response->getAryBestSectionData())->toHaveCount(0);
});

it('stores ghost battle sections and serves the best play per player', function (): void {
    $player = Player::query()->create();

    $ghostStageData1 = new GhostStageData([
        'ary_section_data' => [
            new GhostStageSectionData(['good_cnt' => 100, 'ok_cnt' => 5, 'ng_cnt' => 2, 'pound_cnt' => 3]),
            new GhostStageSectionData(['good_cnt' => 95, 'ok_cnt' => 8, 'ng_cnt' => 4, 'pound_cnt' => 1]),
        ],
    ]);

    $ghostStageData2 = new GhostStageData([
        'ary_section_data' => [
            new GhostStageSectionData(['good_cnt' => 80, 'ok_cnt' => 15, 'ng_cnt' => 10, 'pound_cnt' => 0]),
            new GhostStageSectionData(['good_cnt' => 70, 'ok_cnt' => 20, 'ng_cnt' => 5, 'pound_cnt' => 2]),
        ],
    ]);

    $stage1 = (new StageData)
        ->setSongNo(100)->setLevel(3)->setPlayResult(2)->setPlayScore(876543)
        ->setGoodCnt(500)->setOkCnt(20)->setNgCnt(3)->setPoundCnt(11)
        ->setComboCnt(450)->setHitCnt(523)->setMusicCateg(1)
        ->setSelectedFolderId(7)->setStarLevel(8)->setSupportLevel(0)
        ->setGhostStagedata($ghostStageData1);

    $data1 = (new PlayResultDataRequest)
        ->setBaid($player->baid)->setChassisId('chassis')->setShopId('shop')
        ->setPlayDatetime('2026-05-05 20:00:00')->setIsRight(true)->setCardType(1)
        ->setIsTwoPlayers(false)->setAryStageInfo([$stage1])
        ->setBonusDailyFlg(false)->setBonusWeeklyFlg(false)->setBonusMonthlyFlg(false)
        ->setGetDonmedal(0)->setGetKatsumedal(0)->setGenderType(0)
        ->setPlayerAge(0)->setPlayMode(0)->setAreaCode(0)->setReserved('')
        ->setDifficultyPlayedCourse(3)->setDifficultyPlayedStar(8);

    $request1 = (new PlayResultRequest)
        ->setBaidConf($player->baid)->setChassisIdConf('chassis')->setShopIdConf('shop')
        ->setPlayDatetimeConf('2026-05-05 20:00:00')
        ->setPlayresultData(gzencode($data1->serializeToString()));

    $response1 = post_protobuf('/v08r00/chassis/playresult.php', $request1, PlayResultResponse::class);
    expect($response1->getResult())->toBe(1);

    $stage2 = (new StageData)
        ->setSongNo(100)->setLevel(3)->setPlayResult(2)->setPlayScore(850000)
        ->setGoodCnt(480)->setOkCnt(25)->setNgCnt(5)->setPoundCnt(8)
        ->setComboCnt(430)->setHitCnt(500)->setMusicCateg(1)
        ->setSelectedFolderId(7)->setStarLevel(8)->setSupportLevel(0)
        ->setGhostStagedata($ghostStageData2);

    $data2 = (new PlayResultDataRequest)
        ->setBaid($player->baid)->setChassisId('chassis')->setShopId('shop')
        ->setPlayDatetime('2026-05-05 21:00:00')->setIsRight(true)->setCardType(1)
        ->setIsTwoPlayers(false)->setAryStageInfo([$stage2])
        ->setBonusDailyFlg(false)->setBonusWeeklyFlg(false)->setBonusMonthlyFlg(false)
        ->setGetDonmedal(0)->setGetKatsumedal(0)->setGenderType(0)
        ->setPlayerAge(0)->setPlayMode(0)->setAreaCode(0)->setReserved('')
        ->setDifficultyPlayedCourse(3)->setDifficultyPlayedStar(8);

    $request2 = (new PlayResultRequest)
        ->setBaidConf($player->baid)->setChassisIdConf('chassis')->setShopIdConf('shop')
        ->setPlayDatetimeConf('2026-05-05 21:00:00')
        ->setPlayresultData(gzencode($data2->serializeToString()));

    $response2 = post_protobuf('/v08r00/chassis/playresult.php', $request2, PlayResultResponse::class);
    expect($response2->getResult())->toBe(1);

    expect(SongPlayResult::query()->whereNotNull('ghost_sections')->count())->toBe(2);

    $storedGhost = SongPlayResult::query()->where('song_no', 100)->firstOrFail()->ghost_sections;
    expect($storedGhost[0]['good_cnt'])->toBe(100)
        ->and($storedGhost[1]['ok_cnt'])->toBe(8);

    $ghostScoreRequest = (new GetghostscoreRequest)
        ->setChassisId('chassis')->setShopId('shop')
        ->setBaid(1)->setSongNo(100)->setLevel(3);

    $ghostScoreResponse = post_protobuf('/v11r01/chassis/getghostscore.php', $ghostScoreRequest, GetghostscoreResponse::class);
    expect($ghostScoreResponse->getResult())->toBe(1)
        ->and(count(iterator_to_array($ghostScoreResponse->getAryBestSectionData())))->toBe(2);

    $sections = iterator_to_array($ghostScoreResponse->getAryBestSectionData());
    expect($sections[0]->getGoodCnt())->toBe(100)
        ->and($sections[1]->getOkCnt())->toBe(8);
});

it('keeps ghost battle sections separate per catalog version', function (): void {
    config()->set('taiko_green.route_catalog_versions', [
        'v08r00' => 'ST-10100-1',
        'v11r01' => 'ST-11100-1',
    ]);

    $player = Player::query()->create();

    post_protobuf(
        '/v08r00/chassis/playresult.php',
        play_result_request($player, 200, 900000, ghost_stage_data(12)),
        PlayResultResponse::class,
    );
    post_protobuf(
        '/v11r01/chassis/playresult.php',
        play_result_request($player, 200, 700000, ghost_stage_data(34)),
        PlayResultResponse::class,
    );

    $request = (new GetghostscoreRequest)
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setBaid($player->baid)
        ->setSongNo(200)
        ->setLevel(3);

    $blue = post_protobuf('/v08r00/chassis/getghostscore.php', $request, GetghostscoreResponse::class);
    $green = post_protobuf('/v11r01/chassis/getghostscore.php', $request, GetghostscoreResponse::class);

    expect($blue->getAryBestSectionData()[0]->getGoodCnt())->toBe(12)
        ->and($green->getAryBestSectionData()[0]->getGoodCnt())->toBe(34);
});

it('creates and reloads cards through baidcheck', function (): void {
    $request = (new BAIDRequest)
        ->setDeviceType(1)
        ->setAccessCode('12345678901234567890')
        ->setChipId('chip')
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setCountryId('JPN');

    $first = post_protobuf('/v08r00/chassis/baidcheck.php', $request, BAIDResponse::class);
    $second = post_protobuf('/v08r00/chassis/baidcheck.php', $request, BAIDResponse::class);

    expect($first->getResult())->toBe(1)
        ->and($first->getPlayerType())->toBe(1)
        ->and($second->getPlayerType())->toBe(0)
        ->and($second->getBaid())->toBe($first->getBaid());

    expect(GameCard::query()->where('access_code', '12345678901234567890')->exists())->toBeTrue();
});

it('loads user data for a player', function (): void {
    $player = Player::query()->create([
        'mydon_name' => 'DON',
        'favorite_song_numbers' => [1, 2],
        'recent_song_numbers' => [3],
    ]);

    $request = (new UserDataRequest)
        ->setBaid($player->baid)
        ->setChassisId('chassis')
        ->setShopId('shop');

    $response = post_protobuf('/v08r00/chassis/userdata.php', $request, UserDataResponse::class);

    expect($response->getResult())->toBe(1)
        ->and(iterator_to_array($response->getAryFavoriteSongNo()))->toBe([1, 2])
        ->and(iterator_to_array($response->getAryRecentSongNo()))->toBe([3]);
});

it('saves play results and updates self bests', function (): void {
    $player = Player::query()->create();

    $stage = (new StageData)
        ->setSongNo(100)
        ->setLevel(3)
        ->setPlayResult(2)
        ->setPlayScore(876543)
        ->setGoodCnt(500)
        ->setOkCnt(20)
        ->setNgCnt(3)
        ->setPoundCnt(11)
        ->setComboCnt(450)
        ->setHitCnt(523)
        ->setMusicCateg(1)
        ->setSelectedFolderId(7)
        ->setStarLevel(8)
        ->setSupportLevel(0);

    $data = (new PlayResultDataRequest)
        ->setBaid($player->baid)
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setPlayDatetime('2026-05-05 20:00:00')
        ->setIsRight(true)
        ->setCardType(1)
        ->setIsTwoPlayers(false)
        ->setAryStageInfo([$stage])
        ->setBonusDailyFlg(false)
        ->setBonusWeeklyFlg(false)
        ->setBonusMonthlyFlg(false)
        ->setGetDonmedal(0)
        ->setGetKatsumedal(0)
        ->setGenderType(0)
        ->setPlayerAge(0)
        ->setPlayMode(0)
        ->setAreaCode(0)
        ->setReserved('')
        ->setDifficultyPlayedCourse(3)
        ->setDifficultyPlayedStar(8);

    $request = (new PlayResultRequest)
        ->setBaidConf($player->baid)
        ->setChassisIdConf('chassis')
        ->setShopIdConf('shop')
        ->setPlayDatetimeConf('2026-05-05 20:00:00')
        ->setPlayresultData(gzencode($data->serializeToString()));

    $response = post_protobuf('/v08r00/chassis/playresult.php', $request, PlayResultResponse::class);

    expect($response->getResult())->toBe(1)
        ->and(SongPlayResult::query()->count())->toBe(1)
        ->and(SongPlayResult::query()->first()->game_version)->toBe('green')
        ->and(SongBest::query()->first()->best_score)->toBe(876543);

    $bestRequest = (new SelfBestRequest)
        ->setBaid($player->baid)
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setLevel(3)
        ->setArySongNo([100]);

    $bestResponse = post_protobuf('/v08r00/chassis/selfbest.php', $bestRequest, SelfBestResponse::class);

    expect($bestResponse->getResult())->toBe(1)
        ->and($bestResponse->getArySelfbestScore()[0]->getSelfBestScore())->toBe(876543);
});

it('keeps self bests separate per catalog version', function (): void {
    config()->set('taiko_green.route_catalog_versions', [
        'v08r00' => 'ST-10100-1',
        'v11r01' => 'ST-11100-1',
    ]);

    $player = Player::query()->create();

    post_protobuf('/v08r00/chassis/playresult.php', play_result_request($player, 2, 900000), PlayResultResponse::class);
    post_protobuf('/v11r01/chassis/playresult.php', play_result_request($player, 2, 700000), PlayResultResponse::class);

    expect(SongBest::query()->count())->toBe(2)
        ->and(SongBest::query()->where('game_version', 'blue')->first()->best_score)->toBe(900000)
        ->and(SongBest::query()->where('game_version', 'green')->first()->best_score)->toBe(700000);

    $selfBestRequest = (new SelfBestRequest)
        ->setBaid($player->baid)
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setLevel(3)
        ->setArySongNo([2]);

    $blueBest = post_protobuf('/v08r00/chassis/selfbest.php', $selfBestRequest, SelfBestResponse::class);
    $greenBest = post_protobuf('/v11r01/chassis/selfbest.php', $selfBestRequest, SelfBestResponse::class);

    expect($blueBest->getArySelfbestScore()[0]->getSelfBestScore())->toBe(900000)
        ->and($greenBest->getArySelfbestScore()[0]->getSelfBestScore())->toBe(700000);
});

it('acknowledges anonymous play results without retrying', function (): void {
    $stage = (new StageData)
        ->setSongNo(99)
        ->setLevel(3)
        ->setPlayResult(1)
        ->setPlayScore(249710);

    $data = (new PlayResultDataRequest)
        ->setBaid(0)
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setPlayDatetime('2026-05-07 20:26:20')
        ->setIsRight(false)
        ->setCardType(0)
        ->setIsTwoPlayers(false)
        ->setAryStageInfo([$stage])
        ->setBonusDailyFlg(false)
        ->setBonusWeeklyFlg(false)
        ->setBonusMonthlyFlg(false)
        ->setGetDonmedal(0)
        ->setGetKatsumedal(0)
        ->setGenderType(0)
        ->setPlayerAge(0)
        ->setPlayMode(0)
        ->setAreaCode(0)
        ->setReserved('');

    $request = (new PlayResultRequest)
        ->setBaidConf(0)
        ->setChassisIdConf('chassis')
        ->setShopIdConf('shop')
        ->setPlayDatetimeConf('2026-05-07 20:26:20')
        ->setPlayresultData(gzencode($data->serializeToString()));

    $response = post_protobuf('/v11r01/chassis/playresult.php', $request, PlayResultResponse::class);

    expect($response->getResult())->toBe(1)
        ->and(SongPlayResult::query()->count())->toBe(0);
});

/**
 * @template TMessage of Message
 *
 * @param  class-string<TMessage>  $responseClass
 * @return TMessage
 */
function post_protobuf(string $uri, Message $request, string $responseClass): Message
{
    $response = post_protobuf_raw($uri, $request);

    $message = new $responseClass;
    $message->mergeFromString($response->getContent());

    return $message;
}

function post_protobuf_raw(string $uri, Message $request): TestResponse
{
    $response = test()->call(
        'POST',
        $uri,
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/protobuf', 'HTTP_ACCEPT' => 'application/protobuf'],
        $request->serializeToString(),
    );

    $response->assertOk();

    return $response;
}

function play_result_request(Player $player, int $songNo, int $score, ?GhostStageData $ghostStageData = null): PlayResultRequest
{
    $stage = (new StageData)
        ->setSongNo($songNo)
        ->setLevel(3)
        ->setPlayResult(2)
        ->setPlayScore($score);

    if ($ghostStageData instanceof GhostStageData) {
        $stage->setGhostStagedata($ghostStageData);
    }

    $data = (new PlayResultDataRequest)
        ->setBaid($player->baid)
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setPlayDatetime('2026-05-05 20:00:00')
        ->setIsRight(true)
        ->setCardType(1)
        ->setIsTwoPlayers(false)
        ->setAryStageInfo([$stage])
        ->setBonusDailyFlg(false)
        ->setBonusWeeklyFlg(false)
        ->setBonusMonthlyFlg(false)
        ->setGetDonmedal(0)
        ->setGetKatsumedal(0)
        ->setGenderType(0)
        ->setPlayerAge(0)
        ->setPlayMode(0)
        ->setAreaCode(0)
        ->setReserved('')
        ->setDifficultyPlayedCourse(3)
        ->setDifficultyPlayedStar(8);

    return (new PlayResultRequest)
        ->setBaidConf($player->baid)
        ->setChassisIdConf('chassis')
        ->setShopIdConf('shop')
        ->setPlayDatetimeConf('2026-05-05 20:00:00')
        ->setPlayresultData(gzencode($data->serializeToString()));
}

function create_song(string $version, int $songNo): Song
{
    return Song::query()->create([
        'version' => $version,
        'song_no' => $songNo,
        'music_id' => "{$version}-{$songNo}",
        'unique_id' => $songNo,
        'title' => "Song {$songNo}",
        'genre' => SongGenre::Jpop,
        'partsset' => SongPartsSet::Taiko,
        'wai2_partsset' => SongWai2PartsSet::Taiko,
        'flags' => [],
        'tags' => [],
    ]);
}

function ghost_stage_data(int $goodCount): GhostStageData
{
    return new GhostStageData([
        'ary_section_data' => [
            new GhostStageSectionData(['good_cnt' => $goodCount, 'ok_cnt' => 1, 'ng_cnt' => 0, 'pound_cnt' => 0]),
        ],
    ]);
}
