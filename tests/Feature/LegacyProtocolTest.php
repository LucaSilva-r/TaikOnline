<?php

use App\Enums\SongGenre;
use App\Enums\SongPartsSet;
use App\Enums\SongWai2PartsSet;
use App\Enums\TaikoGameVersion;
use App\GameProtocol\Support\ProtocolMessageResolver;
use App\GameProtocol\Support\ScoreMapper;
use App\Models\GameCard;
use App\Models\Player;
use App\Models\PlayerCosmetic;
use App\Models\PlayerDonPointState;
use App\Models\PlayerVersionStats;
use App\Models\Song;
use App\Models\SongBest;
use App\Models\SongPlayResult;

it('returns Katsu Don song info without dropping the cabinet connection', function (): void {
    $player = Player::query()->create();
    $version = TaikoGameVersion::Katsudon;
    $resolver = app(ProtocolMessageResolver::class);

    SongBest::query()->create([
        'baid' => $player->baid,
        'game_version' => $version->value,
        'song_no' => 386,
        'level' => 3,
        'best_score' => 441440,
    ]);

    $requestClass = $resolver->class($version, 'SongInfoRequest');
    $request = (new $requestClass)
        ->setBaid($player->baid)
        ->setChassisId('268410359554')
        ->setIsFriend(false)
        ->setArySongNo([386]);

    $responseClass = $resolver->class($version, 'SongInfoResponse');
    $response = post_protobuf('/v02r00/chassis/songinfo.php', $request, $responseClass);

    expect($response->getResult())->toBe(1)
        ->and($response->getAryGroupScore())->toHaveCount(1);

    $group = $response->getAryGroupScore()[0];
    expect($group->getSongNo())->toBe(386)
        ->and(iterator_to_array($group->getAryHighScore()))->toBe([0, 0, 0, 441440, 0])
        ->and($group->getAryFriendScore())->toHaveCount(0);
});

it('stores a Katsu Don play result whose stage omits hit count', function (): void {
    $player = Player::query()->create();
    $resolver = app(ProtocolMessageResolver::class);
    $version = TaikoGameVersion::Katsudon;

    $stageClass = $resolver->class($version, 'PlayResultRequest\\StageData');
    $stage = (new $stageClass)
        ->setSongNo(100)
        ->setLevel(3)
        ->setPlayResult(2)
        ->setPlayScore(876543)
        ->setGoodCnt(500)
        ->setOkCnt(20)
        ->setNgCnt(3)
        ->setPoundCnt(11)
        ->setComboCnt(450)
        ->setMusicCateg(1);

    $requestClass = $resolver->class($version, 'PlayResultRequest');
    $request = (new $requestClass)
        ->setBaid($player->baid)
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setPlayDatetime('20260619203524')
        ->setIsRight(true)
        ->setCardType(1)
        ->setIsTwoPlayers(false)
        ->setAryStageInfo([$stage]);

    $responseClass = $resolver->class($version, 'PlayResultResponse');
    $response = post_protobuf('/v02r00/chassis/playresult.php', $request, $responseClass);

    expect($response->getResult())->toBe(1);

    $stored = SongPlayResult::query()
        ->where('baid', $player->baid)
        ->where('game_version', $version->value)
        ->firstOrFail();

    expect($stored->score)->toBe(876543)
        ->and($stored->hit_count)->toBe(523);
});

it('saves cosmetic unlocks and equipped items from a legacy play result request (kimidori)', function (): void {
    $player = Player::query()->create();
    GameCard::query()->create([
        'access_code' => '12345678901234567890',
        'baid' => $player->baid,
    ]);

    $resolver = app(ProtocolMessageResolver::class);
    $mapper = app(ScoreMapper::class);
    $version = TaikoGameVersion::Kimidori;
    $major = $version->routeMajor(); // v05

    // Build the stage info
    $stageClass = $resolver->class($version, 'PlayResultRequest\\StageData');
    $stage = (new $stageClass)
        ->setSongNo(100)
        ->setLevel(3)
        ->setPlayResult(2)
        ->setPlayScore(800000);

    // Build equipped costume
    $equippedCostumeClass = $resolver->class($version, 'PlayResultRequest\\CostumeData');
    $equippedCostume = (new $equippedCostumeClass)
        ->setCostume1(11)
        ->setCostume2(12)
        ->setCostume3(13)
        ->setCostume4(14)
        ->setCostume5(15);

    // Build the request
    $requestClass = $resolver->class($version, 'PlayResultRequest');
    $request = (new $requestClass)
        ->setBaid($player->baid)
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setPlayDatetime('2026-06-12 20:00:00')
        ->setIsRight(true)
        ->setCardType(1)
        ->setIsTwoPlayers(false)
        ->setAryStageInfo([$stage])
        ->setCostumeFlg1($mapper->idFlagBytes([2], 32))
        ->setCostumeFlg3($mapper->idFlagBytes([10], 32))
        ->setToneFlg($mapper->idFlagBytes([3], 16))
        ->setTitleFlg($mapper->idFlagBytes([7], 128))
        ->setCurrentTitle('Legend')
        ->setAryCurrentCostume($equippedCostume);

    $responseClass = $resolver->class($version, 'PlayResultResponse');
    $response = post_protobuf("/{$major}r00/chassis/playresult.php", $request, $responseClass);

    expect($response->getResult())->toBe(1);

    // Assert database was updated correctly
    $cosmetic = PlayerCosmetic::query()
        ->where('baid', $player->baid)
        ->where('game_version', $version->value)
        ->firstOrFail();

    expect($cosmetic->unlocked_tones)->toBe([3])
        ->and($cosmetic->unlocked_titles)->toBe([7])
        ->and($cosmetic->unlocked_costumes['1'])->toBe([2])
        ->and($cosmetic->unlocked_costumes['3'])->toBe([10])
        ->and($cosmetic->title)->toBe('Legend')
        ->and($cosmetic->costume_1)->toBe(11)
        ->and($cosmetic->costume_2)->toBe(12)
        ->and($cosmetic->costume_3)->toBe(13)
        ->and($cosmetic->costume_4)->toBe(14)
        ->and($cosmetic->costume_5)->toBe(15);

    // Verify BAID request returns the saved cosmetics
    $baidReqClass = $resolver->class($version, 'BAIDRequest');
    $baidRequest = (new $baidReqClass)
        ->setAccessCode('12345678901234567890')
        ->setChassisId('chassis')
        ->setShopId('shop');

    $baidRespClass = $resolver->class($version, 'BAIDResponse');
    $baidResponse = post_protobuf("/{$major}r00/chassis/baidcheck.php", $baidRequest, $baidRespClass);

    expect($baidResponse->getResult())->toBe(1)
        ->and($baidResponse->getTitle())->toBe('Legend')
        ->and($baidResponse->getCostumeFlg1())->toBe($mapper->idFlagBytes([2], 32))
        ->and($baidResponse->getCostumeFlg3())->toBe($mapper->idFlagBytes([10], 32))
        ->and($baidResponse->getAryCostumedata()->getCostume1())->toBe(11)
        ->and($baidResponse->getAryCostumedata()->getCostume5())->toBe(15);
});

it('returns correctly-indexed uncompressed crown flags for a legacy crownsdata request (kimidori)', function (): void {
    $player = Player::query()->create();
    $version = TaikoGameVersion::Kimidori;
    $major = $version->routeMajor(); // v05

    // Create some songs for this version
    Song::query()->create([
        'version' => $version->value,
        'song_no' => 10,
        'music_id' => 'kimidori-10',
        'unique_id' => 10,
        'title' => 'Song 10',
        'genre' => SongGenre::Jpop,
        'partsset' => SongPartsSet::Taiko,
        'wai2_partsset' => SongWai2PartsSet::Taiko,
        'flags' => [],
        'tags' => [],
    ]);
    Song::query()->create([
        'version' => $version->value,
        'song_no' => 20,
        'music_id' => 'kimidori-20',
        'unique_id' => 20,
        'title' => 'Song 20',
        'genre' => SongGenre::Jpop,
        'partsset' => SongPartsSet::Taiko,
        'wai2_partsset' => SongWai2PartsSet::Taiko,
        'flags' => [],
        'tags' => [],
    ]);

    // Sorted legacy song order: [10, 20]
    // Song 10 is index 0. Song 20 is index 1.

    // Let's create a score on Song 20 (index 1), Oni difficulty (level 4)
    // with a Gold Crown (state 3)
    SongBest::query()->create([
        'baid' => $player->baid,
        'game_version' => $version->value,
        'song_no' => 20,
        'level' => 4,
        'best_crown' => 2, // Gold
        'best_score' => 950000,
    ]);

    $resolver = app(ProtocolMessageResolver::class);
    $reqClass = $resolver->class($version, 'CrownsDataRequest');
    $request = (new $reqClass)
        ->setBaid($player->baid)
        ->setChassisId('chassis');

    $respClass = $resolver->class($version, 'CrownsDataResponse');
    $response = post_protobuf("/{$major}r00/chassis/crownsdata.php", $request, $respClass);

    expect($response->getResult())->toBe(1);

    $crownFlg = $response->getHashCrownFlg();

    // Verify it is NOT gzipped (should not start with gzip header \x1f\x8b)
    expect(str_starts_with($crownFlg, "\x1f\x8b"))->toBeFalse();

    // There are 2 songs, so 2 * 10 = 20 bits => 3 bytes.
    expect(strlen($crownFlg))->toBe(3);

    // Verify correct bits: byte 2 has bits 16 and 17 set (value 3)
    expect($crownFlg)->toBe(chr(0).chr(0).chr(3));
});

it('packs and unpacks option flags as big-endian for legacy versions (kimidori)', function (): void {
    $player = Player::query()->create();
    GameCard::query()->create([
        'access_code' => '12345678901234567890',
        'baid' => $player->baid,
    ]);

    $version = TaikoGameVersion::Kimidori;
    $major = $version->routeMajor(); // v05

    // 1. Test profile options loading (UserDataResponse)
    $cosmetic = PlayerCosmetic::resolve($player->baid, $version);
    $cosmetic->default_option_setting = 7; // Speed = 7
    $cosmetic->save();

    $resolver = app(ProtocolMessageResolver::class);
    $userReqClass = $resolver->class($version, 'UserDataRequest');
    $userRequest = (new $userReqClass)
        ->setBaid($player->baid)
        ->setChassisId('chassis');

    $userRespClass = $resolver->class($version, 'UserDataResponse');
    $userResponse = post_protobuf("/{$major}r00/chassis/userdata.php", $userRequest, $userRespClass);

    expect($userResponse->getResult())->toBe(1);
    // option_flg and default_option_setting should be big-endian 2 bytes: \x00\x07
    expect($userResponse->getOptionFlg())->toBe("\x00\x07")
        ->and($userResponse->getDefaultOptionSetting())->toBe("\x00\x07");

    // 2. Test play result option saving (decodeOptionFlg)
    $stageClass = $resolver->class($version, 'PlayResultRequest\\StageData');
    $stage = (new $stageClass)
        ->setSongNo(100)
        ->setLevel(3)
        ->setPlayResult(2)
        ->setPlayScore(800000)
        ->setOptionFlg("\x00\x05"); // Speed = 5 (in big endian)

    $requestClass = $resolver->class($version, 'PlayResultRequest');
    $request = (new $requestClass)
        ->setBaid($player->baid)
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setPlayDatetime('2026-06-12 20:00:00')
        ->setIsRight(true)
        ->setCardType(1)
        ->setIsTwoPlayers(false)
        ->setAryStageInfo([$stage]);

    $responseClass = $resolver->class($version, 'PlayResultResponse');
    $response = post_protobuf("/{$major}r00/chassis/playresult.php", $request, $responseClass);

    expect($response->getResult())->toBe(1);

    // Database should be updated with decoded option value (5)
    $cosmetic->refresh();
    expect($cosmetic->default_option_setting)->toBe(5);
});

it('stores Momoiro normal and shin bests separately and returns requested selfbest rows', function (): void {
    $player = Player::query()->create();
    $resolver = app(ProtocolMessageResolver::class);
    $version = TaikoGameVersion::Momoiro;

    $stageClass = $resolver->class($version, 'PlayResultRequest\\StageData');
    $requestClass = $resolver->class($version, 'PlayResultRequest');
    $responseClass = $resolver->class($version, 'PlayResultResponse');

    $normalStage = (new $stageClass)
        ->setSongNo(123)
        ->setLevel(4)
        ->setStageMode(0)
        ->setPlayResult(2)
        ->setPlayScore(700000)
        ->setGoodCnt(100)
        ->setOkCnt(10)
        ->setNgCnt(1)
        ->setPoundCnt(0)
        ->setComboCnt(100)
        ->setMusicCateg(1);

    $shinStage = (new $stageClass)
        ->setSongNo(123)
        ->setLevel(4)
        ->setStageMode(1)
        ->setPlayResult(2)
        ->setPlayScore(900000)
        ->setGoodCnt(100)
        ->setOkCnt(10)
        ->setNgCnt(1)
        ->setPoundCnt(0)
        ->setComboCnt(100)
        ->setMusicCateg(1);

    post_protobuf('/v04r02/chassis/playresult.php', (new $requestClass)
        ->setBaid($player->baid)
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setPlayDatetime('20260629120000')
        ->setIsRight(true)
        ->setCardType(1)
        ->setIsTwoPlayers(false)
        ->setPlayMode(0)
        ->setAryStageInfo([$normalStage]), $responseClass);

    post_protobuf('/v04r02/chassis/playresult.php', (new $requestClass)
        ->setBaid($player->baid)
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setPlayDatetime('20260629120500')
        ->setIsRight(true)
        ->setCardType(1)
        ->setIsTwoPlayers(false)
        ->setPlayMode(0)
        ->setAryStageInfo([$shinStage]), $responseClass);

    expect(SongBest::query()->where('baid', $player->baid)->where('game_version', 'momoiro')->count())->toBe(2)
        ->and(SongBest::query()->where('is_shin', false)->firstOrFail()->best_score)->toBe(700000)
        ->and(SongBest::query()->where('is_shin', true)->firstOrFail()->best_score)->toBe(900000);

    $stats = PlayerVersionStats::query()
        ->where('baid', $player->baid)
        ->where('game_version', $version->value)
        ->firstOrFail();

    expect($stats->ranked_song_count)->toBe(1)
        ->and($stats->total_score)->toBe(700000);

    $selfBestRequestClass = $resolver->class($version, 'SelfBestRequest');
    $selfBestResponseClass = $resolver->class($version, 'SelfBestResponse');
    $selfBest = post_protobuf('/v04r02/chassis/selfbest.php', (new $selfBestRequestClass)
        ->setBaid($player->baid)
        ->setChassisId('chassis')
        ->setLevel(4)
        ->setArySongNo([123, 456]), $selfBestResponseClass);

    expect($selfBest->getResult())->toBe(1)
        ->and($selfBest->getArySelfbestScore())->toHaveCount(2)
        ->and($selfBest->getAryShinSelfbestScore())->toHaveCount(2)
        ->and($selfBest->getArySelfbestScore()[0]->getSongNo())->toBe(123)
        ->and($selfBest->getArySelfbestScore()[0]->getSelfBestScore())->toBe(700000)
        ->and($selfBest->getArySelfbestScore()[1]->getSongNo())->toBe(456)
        ->and($selfBest->getArySelfbestScore()[1]->getSelfBestScore())->toBe(0)
        ->and($selfBest->getAryShinSelfbestScore()[0]->getSelfBestScore())->toBe(900000)
        ->and($selfBest->getAryShinSelfbestScore()[1]->getSelfBestScore())->toBe(0);
});

it('acknowledges unsupported Momoiro play modes without mutating state', function (): void {
    $player = Player::query()->create();
    $resolver = app(ProtocolMessageResolver::class);
    $version = TaikoGameVersion::Momoiro;

    $stageClass = $resolver->class($version, 'PlayResultRequest\\StageData');
    $stage = (new $stageClass)
        ->setSongNo(222)
        ->setLevel(3)
        ->setPlayResult(2)
        ->setPlayScore(800000)
        ->setGoodCnt(100)
        ->setOkCnt(10)
        ->setNgCnt(1)
        ->setPoundCnt(0)
        ->setComboCnt(100)
        ->setMusicCateg(1);

    $requestClass = $resolver->class($version, 'PlayResultRequest');
    $responseClass = $resolver->class($version, 'PlayResultResponse');
    $response = post_protobuf('/v04r02/chassis/playresult.php', (new $requestClass)
        ->setBaid($player->baid)
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setPlayDatetime('20260629121000')
        ->setIsRight(true)
        ->setCardType(1)
        ->setIsTwoPlayers(false)
        ->setPlayMode(2)
        ->setGetDonpoint(50)
        ->setRewardPtn(4)
        ->setRewardProgress(8)
        ->setAryStageInfo([$stage]), $responseClass);

    expect($response->getResult())->toBe(1)
        ->and(SongPlayResult::query()->where('baid', $player->baid)->exists())->toBeFalse()
        ->and(SongBest::query()->where('baid', $player->baid)->exists())->toBeFalse()
        ->and(PlayerDonPointState::query()->where('baid', $player->baid)->exists())->toBeFalse();
});

it('persists legacy Don Point totals and preserves reward progress against zero playresult updates', function (): void {
    $player = Player::query()->create();
    $resolver = app(ProtocolMessageResolver::class);
    $version = TaikoGameVersion::Momoiro;

    $stageClass = $resolver->class($version, 'PlayResultRequest\\StageData');
    $requestClass = $resolver->class($version, 'PlayResultRequest');
    $responseClass = $resolver->class($version, 'PlayResultResponse');

    $makeRequest = function (string $playedAt, int $getDonpoint, int $rewardPtn, int $rewardProgress) use ($player, $stageClass, $requestClass): object {
        $stage = (new $stageClass)
            ->setSongNo(333)
            ->setLevel(3)
            ->setPlayResult(2)
            ->setPlayScore(700000 + $getDonpoint)
            ->setGoodCnt(100)
            ->setOkCnt(10)
            ->setNgCnt(1)
            ->setPoundCnt(0)
            ->setComboCnt(100)
            ->setMusicCateg(1);

        return (new $requestClass)
            ->setBaid($player->baid)
            ->setChassisId('chassis')
            ->setShopId('shop')
            ->setPlayDatetime($playedAt)
            ->setIsRight(true)
            ->setCardType(1)
            ->setIsTwoPlayers(false)
            ->setPlayMode(0)
            ->setGetDonpoint($getDonpoint)
            ->setRewardPtn($rewardPtn)
            ->setRewardProgress($rewardProgress)
            ->setAryStageInfo([$stage]);
    };

    post_protobuf('/v04r02/chassis/playresult.php', $makeRequest('20260629122000', 25, 7, 9), $responseClass);
    post_protobuf('/v04r02/chassis/playresult.php', $makeRequest('20260629122500', 5, 0, 0), $responseClass);

    $state = PlayerDonPointState::query()
        ->where('baid', $player->baid)
        ->where('game_version', 'momoiro')
        ->firstOrFail();

    expect($state->total_get_donpoint)->toBe(30)
        ->and($state->reward_ptn)->toBe(7)
        ->and($state->reward_progress)->toBe(9);

    $userRequestClass = $resolver->class($version, 'UserDataRequest');
    $userResponseClass = $resolver->class($version, 'UserDataResponse');
    $userData = post_protobuf('/v04r02/chassis/userdata.php', (new $userRequestClass)
        ->setBaid($player->baid)
        ->setChassisId('chassis'), $userResponseClass);

    expect($userData->getTotalGetDonpoint())->toBe(30)
        ->and($userData->getTotalUseDonpoint())->toBe(0)
        ->and($userData->getRewardProgress())->toBe(9);
});

it('returns Momoiro userdata crown flags in compact songhash order', function (): void {
    $player = Player::query()->create();
    $version = TaikoGameVersion::Momoiro;

    Song::query()->create([
        'version' => $version->value,
        'song_no' => 10,
        'music_id' => 'momoiro-10',
        'unique_id' => 10,
        'title' => 'Song 10',
        'genre' => SongGenre::Jpop,
        'partsset' => SongPartsSet::Taiko,
        'wai2_partsset' => SongWai2PartsSet::Taiko,
        'flags' => [],
        'tags' => [],
    ]);
    Song::query()->create([
        'version' => $version->value,
        'song_no' => 20,
        'music_id' => 'momoiro-20',
        'unique_id' => 20,
        'title' => 'Song 20',
        'genre' => SongGenre::Jpop,
        'partsset' => SongPartsSet::Taiko,
        'wai2_partsset' => SongWai2PartsSet::Taiko,
        'flags' => [],
        'tags' => [],
    ]);

    SongBest::query()->create([
        'baid' => $player->baid,
        'game_version' => $version->value,
        'song_no' => 10,
        'level' => 1,
        'best_crown' => 1,
        'best_score' => 600000,
    ]);
    SongBest::query()->create([
        'baid' => $player->baid,
        'game_version' => $version->value,
        'song_no' => 20,
        'level' => 4,
        'best_crown' => 2,
        'best_score' => 900000,
    ]);

    $resolver = app(ProtocolMessageResolver::class);
    $requestClass = $resolver->class($version, 'UserDataRequest');
    $responseClass = $resolver->class($version, 'UserDataResponse');
    $response = post_protobuf('/v04r02/chassis/userdata.php', (new $requestClass)
        ->setBaid($player->baid)
        ->setChassisId('chassis'), $responseClass);

    expect($response->getResult())->toBe(1)
        ->and($response->getHashCrownFlg())->toBe(chr(2).chr(192));
});

it('serves Kimidori final support endpoints and persists shopping results', function (): void {
    $player = Player::query()->create();
    $resolver = app(ProtocolMessageResolver::class);
    $mapper = app(ScoreMapper::class);
    $version = TaikoGameVersion::Kimidori;

    PlayerDonPointState::query()->create([
        'baid' => $player->baid,
        'game_version' => $version->value,
        'total_get_donpoint' => 120,
        'total_use_donpoint' => 10,
    ]);

    $mainichiRequestClass = $resolver->class($version, 'MainichisongRequest');
    $mainichiResponseClass = $resolver->class($version, 'MainichisongResponse');
    $mainichi = post_protobuf('/v05r06/chassis/mainichisong.php', (new $mainichiRequestClass)
        ->setChassisId('chassis'), $mainichiResponseClass);

    $bestScoreRequestClass = $resolver->class($version, 'BestScoreRequest');
    $bestScoreResponseClass = $resolver->class($version, 'BestScoreResponse');
    $bestScore = post_protobuf('/v05r06/chassis/bestscore.php', (new $bestScoreRequestClass)
        ->setChassisId('chassis')
        ->setSeqId(42), $bestScoreResponseClass);

    $communicationRequestClass = $resolver->class($version, 'CommunicationLogRequest');
    $communicationResponseClass = $resolver->class($version, 'CommunicationLogResponse');
    $communication = post_protobuf('/v05r06/chassis/communicationlog.php', (new $communicationRequestClass)
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setUpdateDatetime('20260629123000'), $communicationResponseClass);

    $shoppingRequestClass = $resolver->class($version, 'ShoppingResultRequest');
    $shoppingResponseClass = $resolver->class($version, 'ShoppingResultResponse');
    $shopping = post_protobuf('/v05r06/chassis/shoppingresult.php', (new $shoppingRequestClass)
        ->setBaid($player->baid)
        ->setShoppingDatetime('20260629123500')
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setIsRight(true)
        ->setUseDonpoint(35)
        ->setToneFlg($mapper->idFlagBytes([6], 16))
        ->setCostumeFlg1($mapper->idFlagBytes([2], 32))
        ->setCostumeFlg2($mapper->idFlagBytes([4], 32))
        ->setCostumeFlg3($mapper->idFlagBytes([8], 32))
        ->setAryShoppingSongNo([77]), $shoppingResponseClass);

    expect($mainichi->getResult())->toBe(1)
        ->and($mainichi->getSongHashVer())->toBe(99)
        ->and($bestScore->getResult())->toBe(1)
        ->and($bestScore->getSeqId())->toBe(42)
        ->and($bestScore->getLastSeqId())->toBe(42)
        ->and($communication->getResult())->toBe(1)
        ->and($shopping->getResult())->toBe(1)
        ->and($shopping->getTotalGetDonpoint())->toBe(120)
        ->and($shopping->getTotalUseDonpoint())->toBe(45)
        ->and($shopping->getToneFlg())->toBe($mapper->idFlagBytes([6], 16))
        ->and($shopping->getCostumeFlg1())->toBe($mapper->idFlagBytes([2], 32));

    $state = PlayerDonPointState::query()
        ->where('baid', $player->baid)
        ->where('game_version', $version->value)
        ->firstOrFail();
    $cosmetic = PlayerCosmetic::query()
        ->where('baid', $player->baid)
        ->where('game_version', $version->value)
        ->firstOrFail();

    expect($state->total_use_donpoint)->toBe(45)
        ->and($player->refresh()->unlocked_song_numbers)->toBe([77])
        ->and($cosmetic->unlocked_tones)->toBe([6])
        ->and($cosmetic->unlocked_costumes['1'])->toBe([2])
        ->and($cosmetic->unlocked_costumes['2'])->toBe([4])
        ->and($cosmetic->unlocked_costumes['3'])->toBe([8]);
});
