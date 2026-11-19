<?php

use App\Enums\SongGenre;
use App\Enums\SongPartsSet;
use App\Enums\SongWai2PartsSet;
use App\Enums\TaikoGameVersion;
use App\GameProtocol\Proto\Blue\Taiko\BanacoinerrorlogRequest;
use App\GameProtocol\Proto\Blue\Taiko\BanacoinerrorlogResponse;
use App\GameProtocol\Proto\Blue\Taiko\BanacoinpaymentRequest;
use App\GameProtocol\Proto\Blue\Taiko\BanacoinpaymentResponse;
use App\GameProtocol\Proto\Blue\Taiko\GetbanacoininfoRequest;
use App\GameProtocol\Proto\Blue\Taiko\GetbanacoininfoResponse;
use App\GameProtocol\Proto\Blue\Taiko\GetitemshopinfoRequest as BlueGetitemshopinfoRequest;
use App\GameProtocol\Proto\Blue\Taiko\GetitemshopinfoResponse as BlueGetitemshopinfoResponse;
use App\GameProtocol\Proto\Blue\Taiko\ItempurchaseRequest as BlueItempurchaseRequest;
use App\GameProtocol\Proto\Blue\Taiko\ItempurchaseResponse as BlueItempurchaseResponse;
use App\GameProtocol\Proto\Green\Taiko\GetitemshopinfoRequest;
use App\GameProtocol\Proto\Green\Taiko\GetitemshopinfoResponse;
use App\GameProtocol\Proto\Green\Taiko\ItempurchaseRequest;
use App\GameProtocol\Proto\Green\Taiko\ItempurchaseResponse;
use App\GameProtocol\Proto\Green\Taiko\PlayResultDataRequest;
use App\GameProtocol\Proto\Green\Taiko\PlayResultDataRequest\StageData;
use App\GameProtocol\Proto\Green\Taiko\PlayResultRequest;
use App\GameProtocol\Proto\Green\Taiko\PlayResultResponse;
use App\GameProtocol\Proto\Green\Taiko\UserDataRequest;
use App\GameProtocol\Proto\Green\Taiko\UserDataResponse;
use App\GameProtocol\Proto\Yellow\Taiko\GetitemshopinfoRequest as YellowGetitemshopinfoRequest;
use App\GameProtocol\Proto\Yellow\Taiko\GetitemshopinfoResponse as YellowGetitemshopinfoResponse;
use App\Models\Player;
use App\Models\PlayerCosmetic;
use App\Models\PlayerShopItem;
use App\Models\PlayerShopSeasonState;
use App\Models\Song;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Helpers
function test_create_song(string $version, int $songNo): Song
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
    ]);
}

function test_play_result_request(Player $player, int $songNo, int $score, int $getDonmedal): PlayResultRequest
{
    $stage = (new StageData)
        ->setSongNo($songNo)
        ->setLevel(3)
        ->setPlayResult(2)
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
        ->setBonusDailyFlg(false)
        ->setBonusWeeklyFlg(false)
        ->setBonusMonthlyFlg(false)
        ->setGetDonmedal($getDonmedal)
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

it('returns item shop info for Green', function (): void {
    $request = (new GetitemshopinfoRequest)
        ->setChassisId('chassis')
        ->setShopId('shop');

    $response = post_protobuf('/v11r01/chassis/getitemshopinfo.php', $request, GetitemshopinfoResponse::class);

    expect($response->getResult())->toBe(1)
        ->and($response->getSeasonId())->toBe(4)
        ->and($response->getTelop())->toBe('Winter reward shop')
        ->and(count($response->getAryItemshopData()))->toBe(8);

    $items = iterator_to_array($response->getAryItemshopData());
    expect($items[0]->getItemNo())->toBe(1)
        ->and($items[0]->getItemType())->toBe(5) // head
        ->and($items[0]->getItemId())->toBe(117)
        ->and($items[0]->getItemPrice())->toBe(500);
});

it('dates the active season into the current cycle so the cabinet sees last plays in season', function (): void {
    // The cabinet gives every medal bonus when the last play is outside [start, end).
    $this->travelTo('2026-11-19 16:00:00');
    $request = (new GetitemshopinfoRequest)->setChassisId('chassis')->setShopId('shop');

    // Pinned winter (data: 2019-12-05 .. 2020-03-23): the latest start, the first end after now.
    $pinned = post_protobuf('/v11r01/chassis/getitemshopinfo.php', $request, GetitemshopinfoResponse::class);
    expect($pinned->getStartDatetime())->toBe('20251205000000')
        ->and($pinned->getEndDatetime())->toBe('20270323235959');

    // 0 follows the calendar: November is the fall shop (data: 09-04 .. 12-04).
    config()->set('taiko_green.active_shop_season_id', 0);
    $calendar = post_protobuf('/v11r01/chassis/getitemshopinfo.php', $request, GetitemshopinfoResponse::class);
    expect($calendar->getSeasonId())->toBe(3)
        ->and($calendar->getTelop())->toBe('Fall reward shop')
        ->and($calendar->getStartDatetime())->toBe('20260904000000')
        ->and($calendar->getEndDatetime())->toBe('20261204235959');
});

it('returns item shop info for Blue', function (): void {
    $request = (new BlueGetitemshopinfoRequest)
        ->setChassisId('chassis')
        ->setShopId('shop');

    $response = post_protobuf('/v10r00/chassis/getitemshopinfo.php', $request, BlueGetitemshopinfoResponse::class);

    expect($response->getResult())->toBe(1)
        ->and($response->getSeasonId())->toBe(4)
        ->and(count($response->getAryItemshopData()))->toBe(4);

    $items = iterator_to_array($response->getAryItemshopData());
    expect($items[0]->getItemNo())->toBe(1)
        ->and($items[0]->getItemType())->toBe(1) // song
        ->and($items[0]->getItemId())->toBe(780)
        ->and($items[0]->getItemPrice())->toBe(1300);
});

it('returns item shop info for Yellow', function (): void {
    $request = (new YellowGetitemshopinfoRequest)
        ->setChassisId('chassis')
        ->setShopId('shop');

    $response = post_protobuf('/v09r00/chassis/getitemshopinfo.php', $request, YellowGetitemshopinfoResponse::class);

    expect($response->getResult())->toBe(1)
        ->and($response->getSeasonId())->toBe(4)
        ->and(count($response->getAryItemshopData()))->toBe(12);

    // Yellow season 4 item 1: item_type 4 (body), item_id 61, price 500.
    $items = iterator_to_array($response->getAryItemshopData());
    expect($items[0]->getItemNo())->toBe(1)
        ->and($items[0]->getItemType())->toBe(4)
        ->and($items[0]->getItemId())->toBe(61)
        ->and($items[0]->getItemPrice())->toBe(500);
});

it('handles item purchase preflight (item_no == 0) for Green', function (): void {
    $player = Player::query()->create();
    PlayerShopSeasonState::create([
        'baid' => $player->baid,
        'game_version' => 'green',
        'season_id' => 4,
        'total_get_donmedal' => 1000,
        'total_use_donmedal' => 200,
    ]);

    $request = (new ItempurchaseRequest)
        ->setBaid($player->baid)
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setItemNo(0);

    $response = post_protobuf('/v11r01/chassis/itempurchase.php', $request, ItempurchaseResponse::class);

    expect($response->getResult())->toBe(1)
        ->and($response->getTotalGetDonmedal())->toBe(1000)
        ->and($response->getTotalUseDonmedal())->toBe(200);
});

it('handles item purchase preflight (item_no == 0) for Blue', function (): void {
    $player = Player::query()->create();
    PlayerShopSeasonState::create([
        'baid' => $player->baid,
        'game_version' => 'blue',
        'season_id' => 4,
        'total_get_donmedal' => 800,
        'total_use_donmedal' => 300,
    ]);

    $request = (new BlueItempurchaseRequest)
        ->setBaid($player->baid)
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setItemNo(0);

    $response = post_protobuf('/v10r00/chassis/itempurchase.php', $request, BlueItempurchaseResponse::class);

    expect($response->getResult())->toBe(1)
        ->and($response->getTotalGetDonmedal())->toBe(800)
        ->and($response->getTotalUseDonmedal())->toBe(300);
});

it('completes item purchase of song for Green', function (): void {
    $player = Player::query()->create();
    PlayerShopSeasonState::create([
        'baid' => $player->baid,
        'game_version' => 'green',
        'season_id' => 4,
        'total_get_donmedal' => 2000,
        'total_use_donmedal' => 0,
    ]);

    // Green season 4 item_no 5: item_type=1 (song), item_id=865, price=1300
    $request = (new ItempurchaseRequest)
        ->setBaid($player->baid)
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setItemNo(5)
        ->setItemType(1)
        ->setItemId(865)
        ->setItemPrice(1300);

    $response = post_protobuf('/v11r01/chassis/itempurchase.php', $request, ItempurchaseResponse::class);

    expect($response->getResult())->toBe(1)
        ->and($response->getTotalGetDonmedal())->toBe(2000)
        ->and($response->getTotalUseDonmedal())->toBe(1300);

    $player->refresh();
    expect($player->unlocked_song_numbers)->toContain(865);

    expect(PlayerShopItem::query()->where([
        'baid' => $player->baid,
        'game_version' => 'green',
        'season_id' => 4,
        'item_type' => 1,
        'item_id' => 865,
    ])->exists())->toBeTrue();
});

it('completes item purchase of costume for Green', function (): void {
    $player = Player::query()->create();
    PlayerShopSeasonState::create([
        'baid' => $player->baid,
        'game_version' => 'green',
        'season_id' => 4,
        'total_get_donmedal' => 1000,
        'total_use_donmedal' => 0,
    ]);

    // Green season 4 item_no 1: item_type=5 (head), item_id=117, price=500
    $request = (new ItempurchaseRequest)
        ->setBaid($player->baid)
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setItemNo(1)
        ->setItemType(5)
        ->setItemId(117)
        ->setItemPrice(500);

    $response = post_protobuf('/v11r01/chassis/itempurchase.php', $request, ItempurchaseResponse::class);

    expect($response->getResult())->toBe(1)
        ->and($response->getTotalGetDonmedal())->toBe(1000)
        ->and($response->getTotalUseDonmedal())->toBe(500);

    $cosmetic = PlayerCosmetic::resolve($player->baid, TaikoGameVersion::Green);
    expect($cosmetic->unlocked_costumes['2'])->toContain(117);
});

it('completes item purchase of song for Blue', function (): void {
    $player = Player::query()->create();
    PlayerShopSeasonState::create([
        'baid' => $player->baid,
        'game_version' => 'blue',
        'season_id' => 4,
        'total_get_donmedal' => 1500,
        'total_use_donmedal' => 0,
    ]);

    // Blue season 4 item_no 1: item_type=1 (song), item_id=780, price=1300
    $request = (new BlueItempurchaseRequest)
        ->setBaid($player->baid)
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setItemNo(1)
        ->setItemType(1)
        ->setItemId(780)
        ->setItemPrice(1300);

    $response = post_protobuf('/v10r00/chassis/itempurchase.php', $request, BlueItempurchaseResponse::class);

    expect($response->getResult())->toBe(1)
        ->and($response->getTotalGetDonmedal())->toBe(1500)
        ->and($response->getTotalUseDonmedal())->toBe(1300);

    $player->refresh();
    expect($player->unlocked_song_numbers)->toContain(780);
});

it('rejects purchase with insufficient medals', function (): void {
    $player = Player::query()->create();
    PlayerShopSeasonState::create([
        'baid' => $player->baid,
        'game_version' => 'green',
        'season_id' => 4,
        'total_get_donmedal' => 1000,
        'total_use_donmedal' => 0,
    ]);

    // Price is 1300, only has 1000
    $request = (new ItempurchaseRequest)
        ->setBaid($player->baid)
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setItemNo(5)
        ->setItemType(1)
        ->setItemId(865)
        ->setItemPrice(1300);

    $response = post_protobuf('/v11r01/chassis/itempurchase.php', $request, ItempurchaseResponse::class);

    expect($response->getResult())->toBe(0);
});

it('rejects duplicate purchases', function (): void {
    $player = Player::query()->create();
    PlayerShopSeasonState::create([
        'baid' => $player->baid,
        'game_version' => 'green',
        'season_id' => 4,
        'total_get_donmedal' => 3000,
        'total_use_donmedal' => 0,
    ]);

    $request = (new ItempurchaseRequest)
        ->setBaid($player->baid)
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setItemNo(5)
        ->setItemType(1)
        ->setItemId(865)
        ->setItemPrice(1300);

    $response1 = post_protobuf('/v11r01/chassis/itempurchase.php', $request, ItempurchaseResponse::class);
    expect($response1->getResult())->toBe(1);

    $response2 = post_protobuf('/v11r01/chassis/itempurchase.php', $request, ItempurchaseResponse::class);
    expect($response2->getResult())->toBe(0);
});

it('rejects purchase of forged or invalid catalog item', function (): void {
    $player = Player::query()->create();
    PlayerShopSeasonState::create([
        'baid' => $player->baid,
        'game_version' => 'green',
        'season_id' => 4,
        'total_get_donmedal' => 2000,
        'total_use_donmedal' => 0,
    ]);

    // Item ID does not match catalog (865 is catalog item 5)
    $request = (new ItempurchaseRequest)
        ->setBaid($player->baid)
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setItemNo(5)
        ->setItemType(1)
        ->setItemId(999) // invalid ID
        ->setItemPrice(1300);

    $response = post_protobuf('/v11r01/chassis/itempurchase.php', $request, ItempurchaseResponse::class);
    expect($response->getResult())->toBe(0);
});

it('accrues medals from play results to the active season state', function (): void {
    $player = Player::query()->create([
        'total_get_donmedal' => 10,
    ]);

    // Active season is enabled
    config()->set('taiko_green.enable_shop', true);

    $request = test_play_result_request($player, 100, 800000, 50);

    $response = post_protobuf('/v11r01/chassis/playresult.php', $request, PlayResultResponse::class);
    expect($response->getResult())->toBe(1);

    $seasonState = PlayerShopSeasonState::query()->where([
        'baid' => $player->baid,
        'game_version' => 'green',
        'season_id' => 4,
    ])->first();

    expect($seasonState)->not->toBeNull()
        ->and($seasonState->total_get_donmedal)->toBe(50);

    $player->refresh();
    // The player row keeps the lifetime total
    expect($player->total_get_donmedal)->toBe(60);
});

it('accrues medals globally if shop is disabled', function (): void {
    $player = Player::query()->create([
        'total_get_donmedal' => 10,
    ]);

    // Disable shop
    config()->set('taiko_green.enable_shop', false);

    $request = test_play_result_request($player, 100, 800000, 50);

    $response = post_protobuf('/v11r01/chassis/playresult.php', $request, PlayResultResponse::class);
    expect($response->getResult())->toBe(1);

    $seasonState = PlayerShopSeasonState::query()->where([
        'baid' => $player->baid,
        'game_version' => 'green',
        'season_id' => 4,
    ])->first();

    // No season state record should have been created/updated
    expect($seasonState)->toBeNull();

    $player->refresh();
    // Global total should have increased by 50
    expect($player->total_get_donmedal)->toBe(60);
});

it('locks and unlocks active season songs in user data response', function (): void {
    $player = Player::query()->create();

    // Create song 865 (which is in the active winter season 4)
    test_create_song('green', 865);
    // Create song 10 (not in shop)
    test_create_song('green', 10);

    $request = (new UserDataRequest)
        ->setBaid($player->baid)
        ->setChassisId('chassis')
        ->setShopId('shop');

    // 1. Without purchase: song 865 should be filtered out, song 10 is available.
    $response = post_protobuf('/v11r01/chassis/userdata.php', $request, UserDataResponse::class);
    $flag = $response->getHashReleaseSongFlg();

    PlayerShopSeasonState::updateOrCreate([
        'baid' => $player->baid,
        'game_version' => 'green',
        'season_id' => 4,
    ], [
        'total_get_donmedal' => 2000,
        'total_use_donmedal' => 0,
    ]);

    $purchaseRequest = (new ItempurchaseRequest)
        ->setBaid($player->baid)
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setItemNo(5)
        ->setItemType(1)
        ->setItemId(865)
        ->setItemPrice(1300);

    post_protobuf('/v11r01/chassis/itempurchase.php', $purchaseRequest, ItempurchaseResponse::class);

    // 3. Check user data again: song 865 should now be unlocked (flag bytes must differ).
    $responseAfter = post_protobuf('/v11r01/chassis/userdata.php', $request, UserDataResponse::class);
    $flagAfter = $responseAfter->getHashReleaseSongFlg();

    expect($flag)->not->toBe($flagAfter);

    // Bit n = song_no n: 865 is byte 108 bit 1, and only the bought song flips.
    expect(ord($flag[108]) & 0b10)->toBe(0)
        ->and(ord($flagAfter[108]) & 0b10)->toBe(0b10)
        ->and(ord($flagAfter[1]) & 0b100)->toBe(0b100); // song 10, not in the shop
});

it('spends medals carried over from earlier seasons and keeps past purchases owned', function (): void {
    $player = Player::query()->create();
    test_create_song('green', 865);

    // Earned in the fall season; the active season (winter, 4) has none yet.
    PlayerShopSeasonState::query()->create([
        'baid' => $player->baid,
        'game_version' => 'green',
        'season_id' => 3,
        'total_get_donmedal' => 1500,
        'total_use_donmedal' => 0,
    ]);

    $purchase = (new ItempurchaseRequest)
        ->setBaid($player->baid)
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setItemNo(5)
        ->setItemType(1)
        ->setItemId(865)
        ->setItemPrice(1300);

    $response = post_protobuf('/v11r01/chassis/itempurchase.php', $purchase, ItempurchaseResponse::class);
    expect($response->getResult())->toBe(1)
        ->and($response->getTotalGetDonmedal())->toBe(1500)
        ->and($response->getTotalUseDonmedal())->toBe(1300);

    // 200 left: a second buy is a duplicate anyway, and the balance stays 200.
    $again = post_protobuf('/v11r01/chassis/itempurchase.php', $purchase, ItempurchaseResponse::class);
    expect($again->getResult())->toBe(0);

    $player->refresh();
    expect($player->total_use_donmedal)->toBe(1300);

    // Bought in an earlier cycle of this season id: still owned.
    PlayerShopItem::query()->where('baid', $player->baid)->update(['season_id' => 1]);
    $user = post_protobuf('/v11r01/chassis/userdata.php', (new UserDataRequest)
        ->setBaid($player->baid)->setChassisId('chassis')->setShopId('shop'), UserDataResponse::class);
    expect(ord($user->getHashReleaseSongFlg()[108]) & 0b10)->toBe(0b10);
});

it('stub endpoints return successful responses for Banacoin', function (): void {
    // getbanacoininfo.php
    $infoReq = (new GetbanacoininfoRequest)
        ->setChassisId('chassis')
        ->setShopId('shop');
    $infoRes = post_protobuf('/v10r00/chassis/getbanacoininfo.php', $infoReq, GetbanacoininfoResponse::class);
    expect($infoRes->getResult())->toBe(1);

    // banacoinpayment.php
    $payReq = (new BanacoinpaymentRequest)
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setPersonid('person123');
    $payRes = post_protobuf('/v10r00/chassis/banacoinpayment.php', $payReq, BanacoinpaymentResponse::class);
    expect($payRes->getResult())->toBe(1)
        ->and($payRes->getPersonid())->toBe('person123')
        ->and($payRes->getBnidResult())->toBe('Ok');

    // banacoinerrorlog.php
    $errReq = (new BanacoinerrorlogRequest)
        ->setChassisId('chassis')
        ->setShopId('shop');
    $errRes = post_protobuf('/v10r00/chassis/banacoinerrorlog.php', $errReq, BanacoinerrorlogResponse::class);
    expect($errRes->getResult())->toBe(1);
});
