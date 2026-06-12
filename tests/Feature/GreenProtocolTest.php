<?php

use App\Enums\SongGenre;
use App\Enums\SongPartsSet;
use App\Enums\SongWai2PartsSet;
use App\Enums\TaikoGameVersion;
use App\GameProtocol\Proto\Green\Taiko\BAIDRequest;
use App\GameProtocol\Proto\Green\Taiko\BAIDResponse;
use App\GameProtocol\Proto\Green\Taiko\BookKeepingRequest;
use App\GameProtocol\Proto\Green\Taiko\BookKeepingResponse;
use App\GameProtocol\Proto\Green\Taiko\ChallengeCompeRequest;
use App\GameProtocol\Proto\Green\Taiko\ChallengeCompeResponse;
use App\GameProtocol\Proto\Green\Taiko\CrownsDataRequest;
use App\GameProtocol\Proto\Green\Taiko\CrownsDataResponse;
use App\GameProtocol\Proto\Green\Taiko\GetfolderRequest;
use App\GameProtocol\Proto\Green\Taiko\GetfolderResponse;
use App\GameProtocol\Proto\Green\Taiko\GetghostdataRequest;
use App\GameProtocol\Proto\Green\Taiko\GetghostdataResponse;
use App\GameProtocol\Proto\Green\Taiko\GetghostscoreRequest;
use App\GameProtocol\Proto\Green\Taiko\GetghostscoreResponse;
use App\GameProtocol\Proto\Green\Taiko\GettelopRequest;
use App\GameProtocol\Proto\Green\Taiko\GettelopResponse;
use App\GameProtocol\Proto\Green\Taiko\InitialdatacheckRequest;
use App\GameProtocol\Proto\Green\Taiko\InitialdatacheckResponse;
use App\GameProtocol\Proto\Green\Taiko\PlayResultDataRequest;
use App\GameProtocol\Proto\Green\Taiko\PlayResultDataRequest\CostumeData as PlayCostumeData;
use App\GameProtocol\Proto\Green\Taiko\PlayResultDataRequest\StageData;
use App\GameProtocol\Proto\Green\Taiko\PlayResultDataRequest\StageData\GhostStageData;
use App\GameProtocol\Proto\Green\Taiko\PlayResultDataRequest\StageData\GhostStageData\GhostStageSectionData;
use App\GameProtocol\Proto\Green\Taiko\PlayResultRequest;
use App\GameProtocol\Proto\Green\Taiko\PlayResultResponse;
use App\GameProtocol\Proto\Green\Taiko\RecommendRequest;
use App\GameProtocol\Proto\Green\Taiko\RecommendResponse;
use App\GameProtocol\Proto\Green\Taiko\RewardcardcheckRequest;
use App\GameProtocol\Proto\Green\Taiko\RewardcardcheckResponse;
use App\GameProtocol\Proto\Green\Taiko\RewardexecutionRequest;
use App\GameProtocol\Proto\Green\Taiko\RewardexecutionResponse;
use App\GameProtocol\Proto\Green\Taiko\SelfBestRequest;
use App\GameProtocol\Proto\Green\Taiko\SelfBestResponse;
use App\GameProtocol\Proto\Green\Taiko\TaikojukuRequest;
use App\GameProtocol\Proto\Green\Taiko\TaikojukuResponse;
use App\GameProtocol\Proto\Green\Taiko\TournamentcheckRequest;
use App\GameProtocol\Proto\Green\Taiko\TournamentcheckResponse;
use App\GameProtocol\Proto\Green\Taiko\UserDataRequest;
use App\GameProtocol\Proto\Green\Taiko\UserDataResponse;
use App\GameProtocol\Proto\Green\VsInterface\StartupAuthRequest;
use App\GameProtocol\Proto\Green\VsInterface\StartupAuthRequest\OperationData as StartupOperationData;
use App\GameProtocol\Proto\Green\VsInterface\StartupAuthResponse;
use App\GameProtocol\Support\MuchaCrypto;
use App\GameProtocol\Support\ProtocolMessageResolver;
use App\Models\Cabinet;
use App\Models\CabinetBookkeepingLog;
use App\Models\DanCourse;
use App\Models\GameCard;
use App\Models\Player;
use App\Models\PlayerCosmetic;
use App\Models\Song;
use App\Models\SongBest;
use App\Models\SongPlayResult;
use Google\Protobuf\Internal\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

it('echoes mucha board auth country codes for regional taiko red cabinets', function (): void {
    $this->post('/mucha_front/boardauth.do', [
        'gameCd' => 'ST87',
        'gameVer' => 'ST870ASB00.06',
        'countryCd' => 'ASB',
        'sendDate' => '20260527',
        'serialNum' => '3F1F9FB168BA64BD19F842D4968EA93A',
        'placeId' => 'AAA00000',
    ])
        ->assertOk()
        ->assertSee('RESULTS=001', false)
        ->assertSee('COUNTRY_CD=ASB', false)
        ->assertSee('EXPIRATION_DATE=null', false)
        ->assertSee('FORCE_BOOT=0', false);
});

it('responds to mucha registration auth for taiko red cabinets', function (): void {
    $response = $this->post('/mucha_front/regiauth.do', [
        'gameCd' => 'ST87',
        'serialNum' => '268410000000',
        'countryCd' => 'JPN',
        'registrationCd' => 'EB1AB7D6B0ADDDA2',
        'sendDate' => '20260527',
        'useToken' => '0',
        'allToken' => '5',
        'placeId' => 'AAA00000',
        'storeRouterIp' => '127.0.0.1',
    ]);

    $response
        ->assertOk()
        ->assertSee('RESULTS=001', false)
        ->assertSee('ALL_TOKEN=6499cd86289c1307', false)
        ->assertSee('ADD_TOKEN=a3755fbb352db6a3', false);

    parse_str($response->getContent(), $payload);

    $muchaCrypto = app(MuchaCrypto::class);

    expect($muchaCrypto->tokenKey('20260527'))->toBe('72026052')
        ->and($muchaCrypto->decryptToken($payload['ALL_TOKEN'], '72026052'))->toBe('5')
        ->and($muchaCrypto->decryptToken($payload['ADD_TOKEN'], '72026052'))->toBe('0');
});

it('responds to mucha update check like the green reference server', function (): void {
    config()->set('taiko_green.mucha_force_update', false);

    $this->post('/mucha_front/updatacheck.do', ['gameVer' => 'S1210JPN08.18'])
        ->assertOk()
        ->assertSee('RESULTS=001', false)
        ->assertSee('UPDATE_URL_1=https://127.0.0.1:54430/updUrl1/', false)
        ->assertSee('UPDATE_SIZE_1=0', false)
        ->assertDontSee('CHECK_SIZE_1', false)
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

it('accepts regional startup route suffixes used by taiko red', function (): void {
    $request = (new StartupAuthRequest)
        ->setChassisId('268410000000')
        ->setHddVer(800)
        ->setShopId('AAA00000')
        ->setCountryId('ASB')
        ->setAryOperationInfo([
            (new StartupOperationData)->setKeyData(1)->setValueData('1'),
            (new StartupOperationData)->setKeyData(2)->setValueData('2'),
        ]);

    $response = post_protobuf('/v01r00_tw/chassis/startupauth.php', $request, StartupAuthResponse::class);

    expect($response->getResult())->toBe(1)
        ->and($response->getAryOperationInfo())->toHaveCount(2)
        ->and($response->getAryOperationInfo()[0]->getKeyData())->toBe(1)
        ->and($response->getAryOperationInfo()[1]->getValueData())->toBe('2');
});

it('dispatches taiko red root setup protobuf requests', function (): void {
    $initialRequest = (new InitialdatacheckRequest)
        ->setChassisId('268410000000')
        ->setShopId('AAA00000');

    $initialResponse = post_protobuf('/', $initialRequest, InitialdatacheckResponse::class);

    expect($initialResponse->getResult())->toBe(1)
        ->and($initialResponse->getSongHashVer())->toBe(99);

    $telopRequest = (new GettelopRequest)
        ->setChassisId('268410000000')
        ->setShopId('AAA00000')
        ->setTelopId(1);

    $telopResponse = post_protobuf('/', $telopRequest, GettelopResponse::class);

    expect($telopResponse->getResult())->toBe(1)
        ->and($telopResponse->getTelop())->toBe('Hello world');

    $bookKeepingRequest = (new BookKeepingRequest)
        ->setChassisId('268410000000')
        ->setShopId('AAA00000')
        ->setUpdateDate('20260527')
        ->setCreditCost1(2)
        ->setCreditCost2(2)
        ->setCreditSongs1(2)
        ->setCreditSongs2(2);

    $bookKeepingResponse = post_protobuf('/', $bookKeepingRequest, BookKeepingResponse::class);

    expect($bookKeepingResponse->getResult())->toBe(1);
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

    $response1 = post_protobuf('/v11r01/chassis/playresult.php', $request1, PlayResultResponse::class);
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

    $response2 = post_protobuf('/v11r01/chassis/playresult.php', $request2, PlayResultResponse::class);
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

it('keys stored ghost battle sections to the green catalog version', function (): void {
    $player = Player::query()->create();

    post_protobuf(
        '/v11r01/chassis/playresult.php',
        play_result_request($player, 200, 700000, ghost_stage_data(34)),
        PlayResultResponse::class,
    );

    expect(SongPlayResult::query()->where('game_version', 'green')->whereNotNull('ghost_sections')->count())->toBe(1);

    $request = (new GetghostscoreRequest)
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setBaid($player->baid)
        ->setSongNo(200)
        ->setLevel(3);

    $green = post_protobuf('/v11r01/chassis/getghostscore.php', $request, GetghostscoreResponse::class);

    expect($green->getAryBestSectionData()[0]->getGoodCnt())->toBe(34);
});

it('loads server-issued cards through baidcheck and rejects unknown cards', function (): void {
    $player = Player::query()->create();
    GameCard::query()->create([
        'access_code' => '12345678901234567890',
        'baid' => $player->baid,
    ]);

    $request = (new BAIDRequest)
        ->setDeviceType(1)
        ->setAccessCode('12345678901234567890')
        ->setChipId('chip')
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setCountryId('JPN');

    $issued = post_protobuf('/v08r00/chassis/baidcheck.php', $request, BAIDResponse::class);

    $player->update(['mydon_name' => 'DON']);

    $registered = post_protobuf('/v08r00/chassis/baidcheck.php', $request, BAIDResponse::class);

    $unknown = post_protobuf('/v08r00/chassis/baidcheck.php', (clone $request)->setAccessCode('99999999999999999999'), BAIDResponse::class);

    expect($issued->getResult())->toBe(1)
        ->and($issued->getPlayerType())->toBe(1)
        ->and($issued->getBaid())->toBe($player->baid)
        ->and($registered->getPlayerType())->toBe(0)
        ->and($registered->getBaid())->toBe($player->baid)
        ->and($unknown->getResult())->toBe(0)
        ->and($unknown->getComSvrResult())->toBe(0);

    expect(GameCard::query()->where('access_code', '12345678901234567890')->exists())->toBeTrue();
    expect(GameCard::query()->where('access_code', '99999999999999999999')->exists())->toBeFalse();
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

    $response = post_protobuf('/v11r01/chassis/playresult.php', $request, PlayResultResponse::class);

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

    $bestResponse = post_protobuf('/v11r01/chassis/selfbest.php', $bestRequest, SelfBestResponse::class);

    expect($bestResponse->getResult())->toBe(1)
        ->and($bestResponse->getArySelfbestScore()[0]->getSelfBestScore())->toBe(876543);
});

it('keys stored self bests to the green catalog version', function (): void {
    $player = Player::query()->create();

    post_protobuf('/v11r01/chassis/playresult.php', play_result_request($player, 2, 700000), PlayResultResponse::class);

    expect(SongBest::query()->count())->toBe(1)
        ->and(SongBest::query()->where('game_version', 'green')->first()->best_score)->toBe(700000);

    $selfBestRequest = (new SelfBestRequest)
        ->setBaid($player->baid)
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setLevel(3)
        ->setArySongNo([2]);

    $greenBest = post_protobuf('/v11r01/chassis/selfbest.php', $selfBestRequest, SelfBestResponse::class);

    expect($greenBest->getArySelfbestScore()[0]->getSelfBestScore())->toBe(700000);
});

it('records crowns from play results and serves them as a gzip bitfield', function (): void {
    $player = Player::query()->create();

    // play_result_request plays song 100 at level 3 (Hard) with play_result 2 = gold.
    post_protobuf('/v11r01/chassis/playresult.php', play_result_request($player, 100, 876543), PlayResultResponse::class);

    expect(SongBest::query()->where('song_no', 100)->where('level', 3)->first()->best_crown)->toBe(2);

    $request = (new CrownsDataRequest)
        ->setBaid($player->baid)
        ->setChassisId('chassis')
        ->setShopId('shop');

    $response = post_protobuf('/v11r01/chassis/crownsdata.php', $request, CrownsDataResponse::class);

    expect($response->getResult())->toBe(1);

    $inflated = gzdecode($response->getHashCrownFlg());

    // gold crown (wire state 3) on difficulty slot 2 (Hard) => value 3 << 4 = 0x30,
    // packed at bit offset song_no*10 = 1000 => byte 125 holds bits 4 and 5.
    expect(strlen($inflated))->toBe(1280)
        ->and(ord($inflated[125]))->toBe(0x30)
        ->and(array_sum(array_map('ord', str_split($inflated))))->toBe(0x30);
});

it('upgrades a crown without a higher score on a later cleaner play', function (): void {
    $player = Player::query()->create();

    // First play: a clear (play_result 1) at a high score.
    post_protobuf('/v11r01/chassis/playresult.php', play_result_request_with_crown($player, 100, 980000, 1), PlayResultResponse::class);
    expect(SongBest::query()->where('song_no', 100)->first()->best_crown)->toBe(1);

    // Later play: lower score but a full combo (play_result 2). Crown upgrades,
    // score stays.
    post_protobuf('/v11r01/chassis/playresult.php', play_result_request_with_crown($player, 100, 500000, 2), PlayResultResponse::class);

    $best = SongBest::query()->where('song_no', 100)->first();
    expect($best->best_crown)->toBe(2)
        ->and($best->best_score)->toBe(980000);
});

it('grants cosmetic unlocks from a play result and renders them as flag bitsets', function (): void {
    $player = Player::query()->create();

    $stage = (new StageData)->setSongNo(100)->setLevel(3)->setPlayResult(2)->setPlayScore(800000);

    $data = (new PlayResultDataRequest)
        ->setBaid($player->baid)
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setPlayDatetime('2026-05-05 20:00:00')
        ->setIsRight(true)
        ->setAryStageInfo([$stage])
        ->setReleaseSongNo([5])
        ->setGetToneNo([3])
        ->setGetTitleNo([7])
        ->setGetCostumeNo1([2])
        ->setGetCostumeNo3([10])
        ->setReserved('');

    $request = (new PlayResultRequest)
        ->setBaidConf($player->baid)
        ->setChassisIdConf('chassis')
        ->setShopIdConf('shop')
        ->setPlayDatetimeConf('2026-05-05 20:00:00')
        ->setPlayresultData(gzencode($data->serializeToString()));

    post_protobuf('/v11r01/chassis/playresult.php', $request, PlayResultResponse::class);

    $player->refresh();
    expect($player->unlocked_song_numbers)->toBe([5]);

    $cosmetic = PlayerCosmetic::query()->where('baid', $player->baid)->where('game_version', 'green')->firstOrFail();
    expect($cosmetic->unlocked_tones)->toBe([3])
        ->and($cosmetic->unlocked_titles)->toBe([7])
        ->and($cosmetic->unlocked_costumes['1'])->toBe([2])
        ->and($cosmetic->unlocked_costumes['3'])->toBe([10]);

    $userResponse = post_protobuf('/v11r01/chassis/userdata.php', (new UserDataRequest)
        ->setBaid($player->baid)->setChassisId('chassis')->setShopId('shop'), UserDataResponse::class);

    $tone = $userResponse->getToneFlg();
    $title = $userResponse->getTitleFlg();

    expect(strlen($tone))->toBe(16)
        ->and(ord($tone[0]))->toBe(0x08)
        ->and(strlen($title))->toBe(128)
        ->and(ord($title[0]))->toBe(0x80);
});

it('renders costume unlock flags in the baid response', function (): void {
    $player = Player::query()->create();
    PlayerCosmetic::query()->create([
        'baid' => $player->baid,
        'game_version' => 'green',
        'unlocked_costumes' => ['1' => [2], '3' => [10]],
    ]);

    GameCard::query()->create([
        'access_code' => '88888888888888888888',
        'baid' => $player->baid,
        'chip_id' => 'chip',
        'device_type' => '1',
        'country_id' => 'JPN',
    ]);

    $response = post_protobuf('/v11r01/chassis/baidcheck.php', (new BAIDRequest)
        ->setAccessCode('88888888888888888888')->setChipId('chip')
        ->setChassisId('chassis')->setShopId('shop')->setCountryId('JPN'), BAIDResponse::class);

    $flg1 = $response->getCostumeFlg1();
    $flg3 = $response->getCostumeFlg3();

    expect(strlen($flg1))->toBe(32)
        ->and(ord($flg1[0]))->toBe(0x04)
        ->and(ord($flg3[1]))->toBe(0x04);
});

it('persists the equipped costume from a play result and returns it on baid', function (): void {
    $player = Player::query()->create();
    GameCard::query()->create([
        'access_code' => '77777777777777777777',
        'baid' => $player->baid,
        'chip_id' => 'chip',
        'device_type' => '1',
        'country_id' => 'JPN',
    ]);

    $stage = (new StageData)->setSongNo(100)->setLevel(3)->setPlayResult(2)->setPlayScore(800000);

    $data = (new PlayResultDataRequest)
        ->setBaid($player->baid)
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setPlayDatetime('2026-05-05 20:00:00')
        ->setIsRight(true)
        ->setAryStageInfo([$stage])
        ->setAryCurrentCostume((new PlayCostumeData)
            ->setCostume1(11)->setCostume2(22)->setCostume3(33)->setCostume4(44)->setCostume5(55))
        ->setReserved('');

    $request = (new PlayResultRequest)
        ->setBaidConf($player->baid)
        ->setChassisIdConf('chassis')
        ->setShopIdConf('shop')
        ->setPlayDatetimeConf('2026-05-05 20:00:00')
        ->setPlayresultData(gzencode($data->serializeToString()));

    post_protobuf('/v11r01/chassis/playresult.php', $request, PlayResultResponse::class);

    $cosmetic = PlayerCosmetic::query()->where('baid', $player->baid)->where('game_version', 'green')->firstOrFail();
    expect($cosmetic->costume_1)->toBe(11)
        ->and($cosmetic->costume_5)->toBe(55);

    $baid = post_protobuf('/v11r01/chassis/baidcheck.php', (new BAIDRequest)
        ->setAccessCode('77777777777777777777')->setChipId('chip')
        ->setChassisId('chassis')->setShopId('shop')->setCountryId('JPN'), BAIDResponse::class);

    expect($baid->getAryCostumedata()->getCostume1())->toBe(11)
        ->and($baid->getAryCostumedata()->getCostume3())->toBe(33)
        ->and($baid->getAryCostumedata()->getCostume5())->toBe(55);
});

it('keeps costume unlocks scoped to the version that granted them', function (): void {
    $player = Player::query()->create();

    // Grant tone 3 on green (v11); blue (v10) must not see it.
    $data = (new PlayResultDataRequest)
        ->setBaid($player->baid)
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setPlayDatetime('2026-05-05 20:00:00')
        ->setIsRight(true)
        ->setAryStageInfo([(new StageData)->setSongNo(100)->setLevel(3)->setPlayResult(2)->setPlayScore(800000)])
        ->setGetToneNo([3])
        ->setReserved('');

    post_protobuf('/v11r01/chassis/playresult.php', (new PlayResultRequest)
        ->setBaidConf($player->baid)->setChassisIdConf('chassis')->setShopIdConf('shop')
        ->setPlayDatetimeConf('2026-05-05 20:00:00')
        ->setPlayresultData(gzencode($data->serializeToString())), PlayResultResponse::class);

    expect(PlayerCosmetic::query()->where('baid', $player->baid)->where('game_version', 'green')->value('unlocked_tones'))->toBe([3])
        ->and(PlayerCosmetic::query()->where('baid', $player->baid)->where('game_version', 'blue')->exists())->toBeFalse();
});

it('persists the last-used tone and options as the player defaults', function (): void {
    $player = Player::query()->create();
    GameCard::query()->create([
        'access_code' => '66666666666666666666',
        'baid' => $player->baid,
        'chip_id' => 'chip',
        'device_type' => '1',
        'country_id' => 'JPN',
    ]);

    // tone_flg with bit 5 set => tone id 5; option_flg little-endian 10.
    $stage = (new StageData)
        ->setSongNo(100)->setLevel(3)->setPlayResult(2)->setPlayScore(800000)
        ->setToneFlg("\x20")
        ->setOptionFlg("\x0A\x00\x00\x00");

    $data = (new PlayResultDataRequest)
        ->setBaid($player->baid)->setChassisId('chassis')->setShopId('shop')
        ->setPlayDatetime('2026-05-05 20:00:00')->setIsRight(true)
        ->setAryStageInfo([$stage])->setReserved('');

    post_protobuf('/v11r01/chassis/playresult.php', (new PlayResultRequest)
        ->setBaidConf($player->baid)->setChassisIdConf('chassis')->setShopIdConf('shop')
        ->setPlayDatetimeConf('2026-05-05 20:00:00')
        ->setPlayresultData(gzencode($data->serializeToString())), PlayResultResponse::class);

    $cosmetic = PlayerCosmetic::query()->where('baid', $player->baid)->where('game_version', 'green')->firstOrFail();
    expect($cosmetic->default_tone_setting)->toBe(5)
        ->and($cosmetic->default_option_setting)->toBe(10);

    $baid = post_protobuf('/v11r01/chassis/baidcheck.php', (new BAIDRequest)
        ->setAccessCode('66666666666666666666')->setChipId('chip')
        ->setChassisId('chassis')->setShopId('shop')->setCountryId('JPN'), BAIDResponse::class);
    expect($baid->getDefaultToneSetting())->toBe(5);

    $user = post_protobuf('/v11r01/chassis/userdata.php', (new UserDataRequest)
        ->setBaid($player->baid)->setChassisId('chassis')->setShopId('shop'), UserDataResponse::class);
    expect($user->getOptionFlg())->toBe("\x0A\x00\x00\x00");
});

it('returns version-scoped equipped title on baid', function (): void {
    $player = Player::query()->create();
    PlayerCosmetic::query()->create([
        'baid' => $player->baid,
        'game_version' => 'green',
        'title' => 'Master of Don',
        'titleplate_id' => 42,
    ]);
    GameCard::query()->create([
        'access_code' => '55555555555555555555',
        'baid' => $player->baid,
        'chip_id' => 'chip',
        'device_type' => '1',
        'country_id' => 'JPN',
    ]);

    $green = post_protobuf('/v11r01/chassis/baidcheck.php', (new BAIDRequest)
        ->setAccessCode('55555555555555555555')->setChipId('chip')
        ->setChassisId('chassis')->setShopId('shop')->setCountryId('JPN'), BAIDResponse::class);

    expect($green->getTitle())->toBe('Master of Don')
        ->and($green->getTitleplateId())->toBe(42);

    // A version with no cosmetic row returns no equipped title.
    $blue = post_protobuf('/v10r00/chassis/baidcheck.php', (new BAIDRequest)
        ->setAccessCode('55555555555555555555')->setChipId('chip')
        ->setChassisId('chassis')->setShopId('shop')->setCountryId('JPN'), BAIDResponse::class);

    expect($blue->getTitle())->toBe('')
        ->and($blue->getTitleplateId())->toBe(0);
});

it('serves dan dojo courses for requested dan slots', function (): void {
    $dan5 = DanCourse::query()->create([
        'version' => 'green', 'dan' => 5, 'unique_id' => 20008, 'name' => '1kyu', 'difficulty' => 3, 'verup_no' => 1,
    ]);
    $dan5->songs()->createMany([
        ['song_no' => 628, 'level' => 2, 'sort_order' => 0],
        ['song_no' => 94, 'level' => 2, 'sort_order' => 1],
        ['song_no' => 686, 'level' => 2, 'sort_order' => 2],
    ]);
    $dan6 = DanCourse::query()->create([
        'version' => 'green', 'dan' => 6, 'unique_id' => 20010, 'name' => 'shodan', 'difficulty' => 3, 'verup_no' => 1,
    ]);
    $dan6->songs()->create(['song_no' => 372, 'level' => 3, 'sort_order' => 0]);

    // Requesting only dan 5 returns just that course with its songs in order.
    $response = post_protobuf('/v11r01/chassis/taikojuku.php', (new TaikojukuRequest)
        ->setChassisId('chassis')->setShopId('shop')->setGetDan([5]), TaikojukuResponse::class);

    $packs = iterator_to_array($response->getAryJukupackData());
    expect($response->getResult())->toBe(1)
        ->and($packs)->toHaveCount(1)
        ->and($packs[0]->getGetDan())->toBe(5)
        ->and($packs[0]->getVerupNo())->toBe(1);

    $songs = iterator_to_array($packs[0]->getAryJukusongData());
    expect($songs)->toHaveCount(3)
        ->and($songs[0]->getSongNo())->toBe(628)
        ->and($songs[0]->getLevel())->toBe(2)
        ->and($songs[2]->getSongNo())->toBe(686);

    // No requested slots returns every course for the version.
    $all = post_protobuf('/v11r01/chassis/taikojuku.php', (new TaikojukuRequest)
        ->setChassisId('chassis')->setShopId('shop'), TaikojukuResponse::class);
    expect(iterator_to_array($all->getAryJukupackData()))->toHaveCount(2);
});

it('scopes dan dojo courses to the requesting version', function (): void {
    DanCourse::query()->create(['version' => 'green', 'dan' => 5, 'unique_id' => 1, 'name' => 'g', 'difficulty' => 3])
        ->songs()->create(['song_no' => 628, 'level' => 2, 'sort_order' => 0]);

    // Blue (v10) has no courses imported: the dojo comes back empty, not green's.
    $blue = post_protobuf('/v10r00/chassis/taikojuku.php', (new TaikojukuRequest)
        ->setChassisId('chassis')->setShopId('shop')->setGetDan([5]), TaikojukuResponse::class);

    expect(iterator_to_array($blue->getAryJukupackData()))->toHaveCount(0);
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

it('answers the older-version check endpoints (murasaki)', function (): void {
    $resolver = app(ProtocolMessageResolver::class);
    $version = TaikoGameVersion::Murasaki;
    $major = $version->routeMajor();

    $endpoints = [
        ['defaultsong', 'DefaultsongRequest', 'DefaultsongResponse'],
        ['foldercheck', 'FoldercheckRequest', 'FoldercheckResponse'],
        ['telopcheck', 'TelopcheckRequest', 'TelopcheckResponse'],
    ];

    foreach ($endpoints as [$endpoint, $reqName, $respName]) {
        $reqClass = $resolver->class($version, $reqName);
        $request = (new $reqClass)->setChassisId('chassis')->setShopId('shop');

        $response = post_protobuf("/{$major}r00/chassis/{$endpoint}.php", $request, $resolver->class($version, $respName));

        expect($response->getResult())->toBe(1);
    }

    $jukuClass = $resolver->class($version, 'TaikojukuRequest');
    $juku = (new $jukuClass)->setChassisId('chassis')->setShopId('shop')->setGetDan([1, 2, 3]);
    $jukuResponse = post_protobuf("/{$major}r00/chassis/taikojuku.php", $juku, $resolver->class($version, 'TaikojukuResponse'));

    expect($jukuResponse->getResult())->toBe(1)
        ->and(iterator_to_array($jukuResponse->getAryJukupackData()))->toBe([]);
});

it('answers the songhash endpoint (momoiro)', function (): void {
    $resolver = app(ProtocolMessageResolver::class);
    $version = TaikoGameVersion::Momoiro;

    $reqClass = $resolver->class($version, 'SonghashRequest');
    $request = (new $reqClass)->setChassisId('268410000000');

    $response = post_protobuf(
        "/{$version->routeMajor()}r00/chassis/songhash.php",
        $request,
        $resolver->class($version, 'SonghashResponse'),
    );

    expect($response->getResult())->toBe(1)
        ->and($response->getSongHashVer())->toBe(99);
});

it('resolves momoiro message names despite casing drift (TelopCheck)', function (): void {
    $resolver = app(ProtocolMessageResolver::class);
    $version = TaikoGameVersion::Momoiro;

    $reqClass = $resolver->class($version, 'TelopcheckRequest');
    expect($reqClass)->toBe('App\\GameProtocol\\Proto\\Momoiro\\Taiko\\TelopCheckRequest');

    $request = (new $reqClass)->setChassisId('chassis');
    $response = post_protobuf('/v04r00/chassis/telopcheck.php', $request, $resolver->class($version, 'TelopcheckResponse'));

    expect($response->getResult())->toBe(1);
});

it('handles momoiro bookkeeping with the renamed app_play_cnt field', function (): void {
    $resolver = app(ProtocolMessageResolver::class);
    $version = TaikoGameVersion::Momoiro;

    $reqClass = $resolver->class($version, 'BookKeepingRequest');
    $request = (new $reqClass)
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setUpdateDate('20260605')
        ->setAppPlayCnt(7)
        ->setServiceSwCnt(2)
        ->setFreePlayCnt(1);

    $response = post_protobuf('/v04r00/chassis/bookkeeping.php', $request, $resolver->class($version, 'BookKeepingResponse'));

    expect($response->getResult())->toBe(1)
        ->and(CabinetBookkeepingLog::query()->first()->all_play_count)->toBe(7);
});

it('loads issued cards through baidcheck for versions without nested CostumeData', function (TaikoGameVersion $version): void {
    $resolver = app(ProtocolMessageResolver::class);
    $player = Player::query()->create();

    GameCard::query()->create([
        'access_code' => '12345678901234567890',
        'baid' => $player->baid,
    ]);

    $reqClass = $resolver->class($version, 'BAIDRequest');
    $request = (new $reqClass)
        ->setDeviceType(1)
        ->setAccessCode('12345678901234567890')
        ->setChipId('chip')
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setCountryId('JPN');

    $response = post_protobuf("/{$version->routeMajor()}r00/chassis/baidcheck.php", $request, $resolver->class($version, 'BAIDResponse'));

    expect($response->getResult())->toBe(1)
        ->and($response->getPlayerType())->toBe(1)
        ->and($response->getBaid())->toBe($player->baid);
})->with([
    'sorairo' => [TaikoGameVersion::Sorairo],
    'momoiro' => [TaikoGameVersion::Momoiro],
    'kimidori' => [TaikoGameVersion::Kimidori],
]);

it('answers heartbeat in every version dialect from its own route', function (TaikoGameVersion $version): void {
    $resolver = app(ProtocolMessageResolver::class);

    $requestClass = $resolver->class($version, 'HeartBeatRequest');
    $request = (new $requestClass)->setChassisId('chassis');

    $uri = "/{$version->routeMajor()}r00/chassis/heartbeat.php";
    $response = post_protobuf($uri, $request, $resolver->class($version, 'HeartBeatResponse'));

    expect($response->getResult())->toBe(1);
})->with(array_map(
    static fn (TaikoGameVersion $version): array => [$version],
    TaikoGameVersion::cases(),
));

/**
 * @template TMessage of Message
 *
 * @param  class-string<TMessage>  $responseClass
 * @return TMessage
 */
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

function play_result_request_with_crown(Player $player, int $songNo, int $score, int $playResult): PlayResultRequest
{
    $stage = (new StageData)
        ->setSongNo($songNo)
        ->setLevel(3)
        ->setPlayResult($playResult)
        ->setPlayScore($score);

    $data = (new PlayResultDataRequest)
        ->setBaid($player->baid)
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setPlayDatetime('2026-05-05 20:00:00')
        ->setIsRight(true)
        ->setCardType(1)
        ->setIsTwoPlayers(false)
        ->setAryStageInfo([$stage])
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
