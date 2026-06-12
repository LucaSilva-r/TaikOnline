<?php

use App\GameProtocol\Proto\White\Taiko\PlayResultRequest;
use App\GameProtocol\Proto\White\Taiko\PlayResultRequest\StageData;
use App\GameProtocol\Proto\White\Taiko\PlayResultResponse;
use App\Models\Player;
use App\Models\SongBest;
use App\Models\SongPlayResult;

it('stores a White play result whose request omits the difficulty-played fields', function (): void {
    $player = Player::query()->create();

    $stage = (new StageData)
        ->setSongNo(516)->setLevel(4)->setPlayResult(2)->setPlayScore(876543)
        ->setGoodCnt(500)->setOkCnt(20)->setNgCnt(3)->setPoundCnt(11)
        ->setComboCnt(450)->setHitCnt(523)->setMusicCateg(1)
        ->setSelectedFolderId(7);

    // White inlines the play data like Blue but, unlike Green/Blue, its
    // PlayResultRequest has no difficulty_played_* fields at all. Reading them
    // unconditionally used to throw after the result rows were already inserted,
    // so the cabinet got an HTML 500, retried, and duplicated every row.
    $request = (new PlayResultRequest)
        ->setBaid($player->baid)->setChassisId('chassis')->setShopId('shop')
        ->setPlayDatetime('20260612153127')->setIsRight(true)
        ->setIsTwoPlayers(false)->setAryStageInfo([$stage]);

    $response = post_protobuf('/v07r00/chassis/playresult.php', $request, PlayResultResponse::class);

    expect($response->getResult())->toBe(1)
        ->and(SongPlayResult::query()->where('game_version', 'white')->count())->toBe(1);

    $stored = SongPlayResult::query()->where('game_version', 'white')->firstOrFail();
    expect($stored->song_no)->toBe(516)
        ->and($stored->score)->toBe(876543);

    $best = SongBest::query()->where('baid', $player->baid)->where('game_version', 'white')->firstOrFail();
    expect($best->best_score)->toBe(876543);
});
