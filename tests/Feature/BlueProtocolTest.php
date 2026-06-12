<?php

use App\GameProtocol\Proto\Blue\Taiko\BalancecheckRequest;
use App\GameProtocol\Proto\Blue\Taiko\BalancecheckResponse;
use App\GameProtocol\Proto\Blue\Taiko\BattleUserDataRequest;
use App\GameProtocol\Proto\Blue\Taiko\BattleUserDataResponse;
use App\GameProtocol\Proto\Blue\Taiko\PlayResultRequest;
use App\GameProtocol\Proto\Blue\Taiko\PlayResultRequest\ReleaseBattleData;
use App\GameProtocol\Proto\Blue\Taiko\PlayResultRequest\ReleaseBattleData\BattleTokenData;
use App\GameProtocol\Proto\Blue\Taiko\PlayResultRequest\StageData;
use App\GameProtocol\Proto\Blue\Taiko\PlayResultRequest\StageData\BattleStageData;
use App\GameProtocol\Proto\Blue\Taiko\PlayResultRequest\StageData\BattleStageData\BattleNpcData;
use App\GameProtocol\Proto\Blue\Taiko\PlayResultResponse;
use App\Models\Player;
use App\Models\PlayerBlueBattleNpcState;
use App\Models\PlayerBlueBattleState;
use App\Models\PlayerBlueBattleTokenState;
use App\Models\SongBest;
use App\Models\SongPlayResult;

it('stores a play result sent with the inline Blue request shape', function (): void {
    $player = Player::query()->create();

    $stage = (new StageData)
        ->setSongNo(100)->setLevel(3)->setPlayResult(2)->setPlayScore(876543)
        ->setGoodCnt(500)->setOkCnt(20)->setNgCnt(3)->setPoundCnt(11)
        ->setComboCnt(450)->setHitCnt(523)->setMusicCateg(1)
        ->setSelectedFolderId(7);

    // Blue inlines every field directly on PlayResultRequest instead of
    // wrapping them in a compressed `playresultData` blob like Green.
    $request = (new PlayResultRequest)
        ->setBaid($player->baid)->setChassisId('chassis')->setShopId('shop')
        ->setPlayDatetime('20260505200000')->setIsRight(true)
        ->setIsTwoPlayers(false)->setAryStageInfo([$stage])
        ->setDifficultyPlayedCourse(3)->setDifficultyPlayedStar(8);

    $response = post_protobuf('/v10r00/chassis/playresult.php', $request, PlayResultResponse::class);

    expect($response->getResult())->toBe(1)
        ->and(SongPlayResult::query()->where('game_version', 'blue')->count())->toBe(1);

    $stored = SongPlayResult::query()->where('game_version', 'blue')->firstOrFail();
    expect($stored->song_no)->toBe(100)
        ->and($stored->score)->toBe(876543)
        ->and($stored->good_count)->toBe(500);

    $best = SongBest::query()->where('baid', $player->baid)->where('game_version', 'blue')->firstOrFail();
    expect($best->best_score)->toBe(876543);
});

it('handles balance check requests for Blue', function (): void {
    $request = (new BalancecheckRequest)
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setPersonid('person123');

    $response = post_protobuf('/v10r00/chassis/balancecheck.php', $request, BalancecheckResponse::class);

    expect($response->getResult())->toBe(1)
        ->and($response->getPersonid())->toBe('person123')
        ->and($response->getBnidResult())->toBe('Ok')
        ->and($response->getCoinCoupon())->toBe(9999);
});

it('handles battle user data requests for Blue', function (): void {
    $request = (new BattleUserDataRequest)
        ->setBaid(1)
        ->setChassisId('chassis')
        ->setShopId('shop');

    $response = post_protobuf('/v10r00/chassis/battleuserdata.php', $request, BattleUserDataResponse::class);

    expect($response->getResult())->toBe(1)
        ->and($response->getReleaseInfoFlg())->toBe(str_repeat("\x00", 16))
        ->and($response->getReleaseBattleStageFlg())->toBe("\x02".str_repeat("\x00", 7))
        ->and($response->getLastBattleStageId())->toBe(0)
        ->and($response->getLastBossLife())->toBe(0)
        ->and($response->getLastNpcId())->toBe(0)
        ->and($response->getAssignStageId())->toBe(1)
        ->and(count($response->getNpcData()))->toBe(1)
        ->and(count($response->getAryTokenData()))->toBe(1);

    $npc = $response->getNpcData()[0];
    expect($npc->getNpcId())->toBe(0)
        ->and($npc->getTotalExp())->toBe('0')
        ->and($npc->getMaxDpn())->toBe(0)
        ->and($npc->getNpcCostumeId())->toBe(0)
        ->and($npc->getNpcCostumeFlg())->toBe("\x01\x00\x00\x00")
        ->and($npc->getLastSelectSpecial1())->toBe(1)
        ->and($npc->getLastSelectSpecial2())->toBe(0)
        ->and($npc->getLastSelectSpecial3())->toBe(0)
        ->and($npc->getReleaseSpecialFlg())->toBe("\x02".str_repeat("\x00", 14)."\x01");

    $token = $response->getAryTokenData()[0];
    expect($token->getTokenId())->toBe(0)
        ->and($token->getTokenValue())->toBe(0);
});

it('saves battle stage and release data for Blue', function (): void {
    $player = Player::query()->create();

    $battleNpc = (new BattleNpcData)
        ->setNpcId(3)
        ->setAcquiredExp('100')
        ->setTotalExp('150')
        ->setDpn(5)
        ->setNpcCostumeId(2)
        ->setSpecialId1(1)
        ->setSpecialId2(2)
        ->setSpecialId3(0)
        ->setBondsLv(4);

    $battleStage = (new BattleStageData)
        ->setSupportLv(1)
        ->setBattleStageId(5)
        ->setNpcData($battleNpc)
        ->setKillCnt(2)
        ->setBossLife(20)
        ->setTotalDamage(50)
        ->setCriticalCnt(3)
        ->setSpecialMoveCnt(1);

    $stage = (new StageData)
        ->setSongNo(100)->setLevel(3)->setPlayResult(2)->setPlayScore(876543)
        ->setGoodCnt(500)->setOkCnt(20)->setNgCnt(3)->setPoundCnt(11)
        ->setComboCnt(450)->setHitCnt(523)->setMusicCateg(1)
        ->setSelectedFolderId(7)
        ->setAryBattlestagedata($battleStage);

    $token = (new BattleTokenData)
        ->setTokenId(4)
        ->setTokenValue(10);

    $releaseData = (new ReleaseBattleData)
        ->setReleaseInfoId([2, 3])
        ->setReleaseBattleStageId([2])
        ->setReleaseNpcId([1])
        ->setReleaseNpcCostumeId([2])
        ->setReleaseNpcSpecialId([3])
        ->setAryBattletokendata([$token])
        ->setAssignNextStageId(6);

    $request = (new PlayResultRequest)
        ->setBaid($player->baid)->setChassisId('chassis')->setShopId('shop')
        ->setPlayDatetime('20260505200000')->setIsRight(true)
        ->setIsTwoPlayers(false)->setAryStageInfo([$stage])
        ->setDifficultyPlayedCourse(3)->setDifficultyPlayedStar(8)
        ->setAryReleaseBattledata($releaseData);

    $response = post_protobuf('/v10r00/chassis/playresult.php', $request, PlayResultResponse::class);

    expect($response->getResult())->toBe(1);

    $userState = PlayerBlueBattleState::query()->where('baid', $player->baid)->firstOrFail();
    expect($userState->last_battle_stage_id)->toBe(5)
        ->and($userState->last_boss_life)->toBe(20)
        ->and($userState->last_npc_id)->toBe(3)
        ->and($userState->assign_stage_id)->toBe(6)
        ->and(ord($userState->release_info_flg[0]))->toBe(12)
        ->and(ord($userState->release_battle_stage_flg[0]))->toBe(4);

    $npcState = PlayerBlueBattleNpcState::query()->where('baid', $player->baid)->where('npc_id', 3)->firstOrFail();
    expect($npcState->total_exp)->toBe(150)
        ->and($npcState->max_dpn)->toBe(5)
        ->and($npcState->npc_costume_id)->toBe(2)
        ->and($npcState->selected_special_id_1)->toBe(1)
        ->and($npcState->selected_special_id_2)->toBe(2)
        ->and($npcState->selected_special_id_3)->toBe(0)
        ->and($npcState->bonds_level)->toBe(4)
        ->and(ord($npcState->npc_costume_flg[0]))->toBe(4)
        ->and(ord($npcState->release_special_flg[0]))->toBe(14);

    $tokenState = PlayerBlueBattleTokenState::query()->where('baid', $player->baid)->where('token_id', 4)->firstOrFail();
    expect($tokenState->token_value)->toBe(10);
});
