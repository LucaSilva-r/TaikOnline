<?php

use App\GameProtocol\Proto\Blue\Taiko\PlayResultRequest;
use App\GameProtocol\Proto\Blue\Taiko\PlayResultRequest\StageData;
use App\GameProtocol\Proto\Blue\Taiko\PlayResultResponse;
use App\Models\Player;
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
