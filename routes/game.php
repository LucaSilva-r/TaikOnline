<?php

use App\Http\Controllers\Green\AllNetController;
use App\Http\Controllers\Green\GameProtocolController;
use App\Http\Controllers\Green\VsInterfaceController;
use App\Http\Controllers\ZucchiniCardController;
use App\Http\Middleware\LogGreenCabinetTraffic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

$protocolVersionPattern = 'v[0-9]{2}r[0-9]{2}(?:_[a-z]{2})?';

Route::post('api/zucchini/cards', ZucchiniCardController::class)
    ->middleware(['zucchini.token', 'throttle:zucchini-cards']);

Route::middleware(LogGreenCabinetTraffic::class)->group(function () use ($protocolVersionPattern): void {
    Route::post('sys/servlet/PowerOn', [AllNetController::class, 'powerOn']);
    Route::post('mucha_front/boardauth.do', [AllNetController::class, 'boardAuth']);
    Route::post('mucha_front/regiauth.do', [AllNetController::class, 'regiAuth']);
    Route::post('mucha_front/updatacheck.do', [AllNetController::class, 'updateCheck']);
    Route::post('mucha_front/downloadstate.do', [AllNetController::class, 'muchaDownloadState']);
    Route::post('mucha_front/downloaderror.do', [AllNetController::class, 'muchaDownloadError']);
    Route::match(['get', 'head'], 'updUrl1/{file?}', [AllNetController::class, 'muchaChunkImage'])->where('file', '.*');
    Route::match(['get', 'head'], 'checkUrl/{file?}', [AllNetController::class, 'muchaChunkImage'])->where('file', '.*');
    Route::post('muchja_activation/signature', [AllNetController::class, 'activationSignature']);
    Route::post('mucha_activation/otk', [AllNetController::class, 'activationOtk']);
    Route::post('v1/s12-jp-dev/garm.SystemBoard/RegisterSystemBoard', [AllNetController::class, 'garm']);
    Route::post('v1/s12-jp-dev/garm.SystemBoard/RegisterSystemBoardBilling', [AllNetController::class, 'garm']);
    Route::post('v1/s12-jp-dev/garm.Monitoring/Ping', [AllNetController::class, 'garm']);
    Route::post('/', [GameProtocolController::class, 'rootSetup']);

    Route::post('{version}/chassis/startupauth.php', [VsInterfaceController::class, 'startupAuth'])->where('version', $protocolVersionPattern);
    Route::post('{version}/chassis/verupauth.php', [VsInterfaceController::class, 'verupAuth'])->where('version', $protocolVersionPattern);
    Route::post('{version}/chassis/verupcomplete.php', [VsInterfaceController::class, 'verupComplete'])->where('version', $protocolVersionPattern);
    Route::post('{version}/chassis/heartbeat.php', [GameProtocolController::class, 'heartbeat'])->where('version', $protocolVersionPattern);
    Route::post('{version}/chassis/initialdatacheck.php', [GameProtocolController::class, 'initialDataCheck'])->where('version', $protocolVersionPattern);
    Route::post('{version}/chassis/bookkeeping.php', [GameProtocolController::class, 'bookKeeping'])->where('version', $protocolVersionPattern);
    Route::post('{version}/chassis/baidcheck.php', [GameProtocolController::class, 'baid'])->where('version', $protocolVersionPattern);
    Route::post('{version}/chassis/mydonentry.php', [GameProtocolController::class, 'mydonEntry'])->where('version', $protocolVersionPattern);
    Route::post('{version}/chassis/userdata.php', [GameProtocolController::class, 'userData'])->where('version', $protocolVersionPattern);
    Route::post('{version}/chassis/playresult.php', [GameProtocolController::class, 'playResult'])->where('version', $protocolVersionPattern);
    Route::post('{version}/chassis/selfbest.php', [GameProtocolController::class, 'selfBest'])->where('version', $protocolVersionPattern);
    Route::post('{version}/chassis/crownsdata.php', [GameProtocolController::class, 'crownsData'])->where('version', $protocolVersionPattern);
    Route::post('{version}/chassis/getfolder.php', [GameProtocolController::class, 'getFolder'])->where('version', $protocolVersionPattern);
    Route::post('{version}/chassis/gettelop.php', [GameProtocolController::class, 'getTelop'])->where('version', $protocolVersionPattern);
    Route::post('{version}/chassis/defaultsong.php', [GameProtocolController::class, 'defaultSong'])->where('version', $protocolVersionPattern);
    Route::post('{version}/chassis/foldercheck.php', [GameProtocolController::class, 'folderCheck'])->where('version', $protocolVersionPattern);
    Route::post('{version}/chassis/telopcheck.php', [GameProtocolController::class, 'telopCheck'])->where('version', $protocolVersionPattern);
    Route::post('{version}/chassis/taikojuku.php', [GameProtocolController::class, 'taikojuku'])->where('version', $protocolVersionPattern);
    Route::post('{version}/chassis/songhash.php', [GameProtocolController::class, 'songHash'])->where('version', $protocolVersionPattern);
    Route::post('{version}/chassis/balancecheck.php', [GameProtocolController::class, 'balanceCheck'])->where('version', $protocolVersionPattern);
    Route::post('{version}/chassis/battleuserdata.php', [GameProtocolController::class, 'battleUserData'])->where('version', $protocolVersionPattern);
    Route::post('{version}/chassis/getghostdata.php', [GameProtocolController::class, 'getGhostData'])->where('version', $protocolVersionPattern);
    Route::post('{version}/chassis/getghostscore.php', [GameProtocolController::class, 'getGhostScore'])->where('version', $protocolVersionPattern);
    Route::post('{version}/chassis/recommend.php', [GameProtocolController::class, 'recommend'])->where('version', $protocolVersionPattern);
    Route::post('{version}/chassis/tournamentcheck.php', [GameProtocolController::class, 'tournamentCheck'])->where('version', $protocolVersionPattern);
    Route::post('{version}/chassis/challengecompe.php', [GameProtocolController::class, 'challengeCompe'])->where('version', $protocolVersionPattern);
    Route::post('{version}/chassis/rewardcardcheck.php', [GameProtocolController::class, 'rewardCardCheck'])->where('version', $protocolVersionPattern);
    Route::post('{version}/chassis/rewardexecution.php', [GameProtocolController::class, 'rewardExecution'])->where('version', $protocolVersionPattern);
    Route::post('{version}/chassis/headclerk2.php', [GameProtocolController::class, 'headClerk2'])->where('version', $protocolVersionPattern);
    Route::post('{version}/chassis/getitemshopinfo.php', [GameProtocolController::class, 'getItemShopInfo'])->where('version', $protocolVersionPattern);
    Route::post('{version}/chassis/itempurchase.php', [GameProtocolController::class, 'itemPurchase'])->where('version', $protocolVersionPattern);
    Route::post('{version}/chassis/getbanacoininfo.php', [GameProtocolController::class, 'getBanacoinInfo'])->where('version', $protocolVersionPattern);
    Route::post('{version}/chassis/banacoinpayment.php', [GameProtocolController::class, 'banacoinPayment'])->where('version', $protocolVersionPattern);
    Route::post('{version}/chassis/banacoinerrorlog.php', [GameProtocolController::class, 'banacoinErrorLog'])->where('version', $protocolVersionPattern);
});

// Catch-all so we observe whatever the cabinet hits but we don't yet route.
// Logs path + method + body to storage/logs/mucha.log so we can see what
// test menu probes (e.g. network check 3) need next.
Route::any('{any?}', function (Request $request) {
    Log::channel('mucha')->warning('unhandled cab request', [
        'method' => $request->method(),
        'path' => '/'.ltrim($request->path(), '/'),
        'host' => $request->getHost(),
        'port' => $request->getPort(),
        'query' => $request->query(),
        'form' => $request->all(),
        'body_sha256' => hash('sha256', $request->getContent()),
        'body_hex_prefix' => bin2hex(substr($request->getContent(), 0, 64)),
        'headers' => collect($request->headers->all())->only(['host', 'user-agent', 'content-type', 'content-length'])->all(),
    ]);

    return response()->json(['message' => 'Not Found'], 404);
})->where('any', '.*')->fallback();
