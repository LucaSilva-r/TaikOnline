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
use App\Models\Song;
use App\Models\SongBest;

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
