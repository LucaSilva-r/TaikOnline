<?php

use App\GameProtocol\Green\Proto\Taiko\BAIDRequest;
use App\GameProtocol\Green\Proto\Taiko\BAIDResponse;
use App\GameProtocol\Green\Proto\Taiko\PlayResultDataRequest;
use App\GameProtocol\Green\Proto\Taiko\PlayResultDataRequest\StageData;
use App\GameProtocol\Green\Proto\Taiko\PlayResultRequest;
use App\GameProtocol\Green\Proto\Taiko\PlayResultResponse;
use App\GameProtocol\Green\Proto\Taiko\SelfBestRequest;
use App\GameProtocol\Green\Proto\Taiko\SelfBestResponse;
use App\GameProtocol\Green\Proto\Taiko\UserDataRequest;
use App\GameProtocol\Green\Proto\Taiko\UserDataResponse;
use App\GameProtocol\Green\Proto\VsInterface\StartupAuthRequest;
use App\GameProtocol\Green\Proto\VsInterface\StartupAuthRequest\OperationData as StartupOperationData;
use App\GameProtocol\Green\Proto\VsInterface\StartupAuthResponse;
use App\Models\GameCard;
use App\Models\Player;
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
        ->assertSee('PLACE_ID=JPN9999', false)
        ->assertSee('CONSUME_TOKEN=0', false);
});

it('responds to mucha update check like the green reference server', function (): void {
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
    $request = (new StartupAuthRequest)
        ->setChassisId('chassis')
        ->setHddVer(1)
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

/**
 * @template TMessage of Message
 *
 * @param  class-string<TMessage>  $responseClass
 * @return TMessage
 */
function post_protobuf(string $uri, Message $request, string $responseClass): Message
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
    $message = new $responseClass;
    $message->mergeFromString($response->getContent());

    return $message;
}
