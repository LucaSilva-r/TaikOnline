<?php

use App\Http\Controllers\Green\AllNetController;
use App\Http\Controllers\Green\GameProtocolController;
use App\Http\Controllers\Green\VsInterfaceController;
use App\Http\Middleware\LogGreenCabinetTraffic;
use Illuminate\Support\Facades\Route;

Route::middleware(LogGreenCabinetTraffic::class)->group(function (): void {
    Route::post('sys/servlet/PowerOn', [AllNetController::class, 'powerOn']);
    Route::post('mucha_front/boardauth.do', [AllNetController::class, 'boardAuth']);
    Route::post('mucha_front/updatacheck.do', [AllNetController::class, 'updateCheck']);
    Route::post('mucha_activation/signature', [AllNetController::class, 'activationSignature']);
    Route::post('mucha_activation/otk', [AllNetController::class, 'activationOtk']);
    Route::post('v1/s12-jp-dev/garm.SystemBoard/RegisterSystemBoard', [AllNetController::class, 'garm']);
    Route::post('v1/s12-jp-dev/garm.SystemBoard/RegisterSystemBoardBilling', [AllNetController::class, 'garm']);
    Route::post('v1/s12-jp-dev/garm.Monitoring/Ping', [AllNetController::class, 'garm']);

    Route::post('{version}/chassis/startupauth.php', [VsInterfaceController::class, 'startupAuth'])->where('version', 'v[0-9]{2}r[0-9]{2}');
    Route::post('{version}/chassis/verupauth.php', [VsInterfaceController::class, 'verupAuth'])->where('version', 'v[0-9]{2}r[0-9]{2}');
    Route::post('{version}/chassis/verupcomplete.php', [VsInterfaceController::class, 'verupComplete'])->where('version', 'v[0-9]{2}r[0-9]{2}');
    Route::post('{version}/chassis/heartbeat.php', [GameProtocolController::class, 'heartbeat'])->where('version', 'v[0-9]{2}r[0-9]{2}');
    Route::post('{version}/chassis/initialdatacheck.php', [GameProtocolController::class, 'initialDataCheck'])->where('version', 'v[0-9]{2}r[0-9]{2}');
    Route::post('{version}/chassis/bookkeeping.php', [GameProtocolController::class, 'bookKeeping'])->where('version', 'v[0-9]{2}r[0-9]{2}');
    Route::post('{version}/chassis/baidcheck.php', [GameProtocolController::class, 'baid'])->where('version', 'v[0-9]{2}r[0-9]{2}');
    Route::post('{version}/chassis/mydonentry.php', [GameProtocolController::class, 'mydonEntry'])->where('version', 'v[0-9]{2}r[0-9]{2}');
    Route::post('{version}/chassis/userdata.php', [GameProtocolController::class, 'userData'])->where('version', 'v[0-9]{2}r[0-9]{2}');
    Route::post('{version}/chassis/playresult.php', [GameProtocolController::class, 'playResult'])->where('version', 'v[0-9]{2}r[0-9]{2}');
    Route::post('{version}/chassis/selfbest.php', [GameProtocolController::class, 'selfBest'])->where('version', 'v[0-9]{2}r[0-9]{2}');
    Route::post('{version}/chassis/crownsdata.php', [GameProtocolController::class, 'crownsData'])->where('version', 'v[0-9]{2}r[0-9]{2}');
    Route::post('{version}/chassis/getfolder.php', [GameProtocolController::class, 'getFolder'])->where('version', 'v[0-9]{2}r[0-9]{2}');
    Route::post('{version}/chassis/gettelop.php', [GameProtocolController::class, 'getTelop'])->where('version', 'v[0-9]{2}r[0-9]{2}');
    Route::post('{version}/chassis/recommend.php', [GameProtocolController::class, 'recommend'])->where('version', 'v[0-9]{2}r[0-9]{2}');
    Route::post('{version}/chassis/tournamentcheck.php', [GameProtocolController::class, 'tournamentCheck'])->where('version', 'v[0-9]{2}r[0-9]{2}');
    Route::post('{version}/chassis/challengecompe.php', [GameProtocolController::class, 'challengeCompe'])->where('version', 'v[0-9]{2}r[0-9]{2}');
    Route::post('{version}/chassis/rewardcardcheck.php', [GameProtocolController::class, 'rewardCardCheck'])->where('version', 'v[0-9]{2}r[0-9]{2}');
    Route::post('{version}/chassis/rewardexecution.php', [GameProtocolController::class, 'rewardExecution'])->where('version', 'v[0-9]{2}r[0-9]{2}');
    Route::post('{version}/chassis/headclerk2.php', [GameProtocolController::class, 'headClerk2'])->where('version', 'v[0-9]{2}r[0-9]{2}');
});
