<?php

namespace App\Http\Controllers\Green;

use App\GameProtocol\Green\Support\FormPayloads;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AllNetController extends Controller
{
    public function __construct(private readonly FormPayloads $forms) {}

    public function powerOn(Request $request): Response
    {
        $now = now();
        $allNetHost = (string) config('taiko_green.allnet_host');

        return $this->formResponse($this->forms->encode([
            'stat' => '1',
            'uri' => $allNetHost,
            'host' => $allNetHost,
            'place_id' => (string) config('taiko_green.place_id'),
            'name' => (string) config('taiko_green.shop_name'),
            'nickname' => (string) config('taiko_green.shop_name'),
            'region0' => (string) config('taiko_green.region'),
            'region_name0' => (string) config('taiko_green.shop_name'),
            'region_name1' => 'X',
            'region_name2' => 'Y',
            'region_name3' => 'Z',
            'country' => (string) config('taiko_green.country'),
            'allnet_id' => '456',
            'timezone' => '002,00',
            'setting' => '',
            'year' => $now->format('Y'),
            'month' => $now->format('n'),
            'day' => $now->format('j'),
            'hour' => $now->format('G'),
            'minute' => (string) (int) $now->format('i'),
            'second' => (string) (int) $now->format('s'),
            'res_class' => 'PowerOnResponseVer2',
            'token' => '123',
        ])."\n");
    }

    public function boardAuth(Request $request): Response
    {
        $placeId = (string) ($request->input('place_id') ?? $request->input('placeId') ?? '');
        $muchaGameUrl = (string) config('taiko_green.mucha_game_url');
        $serverTime = now()->format('YmdHi');

        return $this->formResponse([
            'RESULTS' => '001',
            'AREA_0' => '008',
            'AREA_0_EN' => '',
            'AREA_1' => '009',
            'AREA_1_EN' => '',
            'AREA_2' => '010',
            'AREA_2_EN' => '',
            'AREA_3' => '011',
            'AREA_3_EN' => '',
            'AREA_FULL_0' => '',
            'AREA_FULL_0_EN' => '',
            'AREA_FULL_1' => '',
            'AREA_FULL_1_EN' => '',
            'AREA_FULL_2' => '',
            'AREA_FULL_2_EN' => '',
            'AREA_FULL_3' => '',
            'AREA_FULL_3_EN' => '',
            'AUTH_INTERVAL' => '86400',
            'CHARGE_URL' => $muchaGameUrl.'/charge/',
            'COUNTRY_CD' => (string) config('taiko_green.country'),
            'DONGLE_FLG' => '1',
            'EXPIRATION_DATE' => 'null',
            'FILE_URL' => $muchaGameUrl.'/file/',
            'FORCE_BOOT' => '0',
            'PLACE_ID' => $placeId,
            'PREFECTURE_ID' => '14',
            'SERVER_TIME' => $serverTime,
            'UTC_SERVER_TIME' => now('UTC')->format('YmdHi'),
            'SHOP_NAME' => (string) config('taiko_green.shop_name'),
            'SHOP_NAME_EN' => (string) config('taiko_green.shop_name'),
            'SHOP_NICKNAME' => 'W',
            'SHOP_NICKNAME_EN' => 'W',
            'URL_1' => $muchaGameUrl.'/url1/',
            'URL_2' => $muchaGameUrl.'/url2/',
            'URL_3' => $muchaGameUrl.'/url3/',
            'USE_TOKEN' => '0',
            'CONSUME_TOKEN' => '0',
        ]);
    }

    public function updateCheck(Request $request): Response
    {
        $gameVersion = (string) ($request->input('gameVer') ?? $request->input('game_ver') ?? $request->input('gameVersion') ?? 'S1110JPN13.02');
        $muchaGameUrl = (string) config('taiko_green.mucha_game_url');

        return $this->formResponse([
            'RESULTS' => '001',
            'UPDATE_URL_1' => $muchaGameUrl.'/updUrl1/',
            'UPDATE_SIZE_1' => '20',
            'UPDATE_CRC_1' => '00000000',
            'CHECK_URL_1' => $muchaGameUrl.'/checkUrl/',
            'CHECK_SIZE_1' => '20',
            'CHECK_CRC_1' => '00000000',
            'EXE_VER_1' => $gameVersion,
            'INFO_SIZE_1' => '0',
            'COM_SIZE_1' => '0',
            'COM_TIME_1' => '0',
            'LAN_INFO_SIZE_1' => '0',
            'USER_ID' => '1',
            'PASSWORD' => '1',
            'EXE_VER' => $gameVersion,
        ]);
    }

    public function activationSignature(): array
    {
        return ['status' => 1, 'message' => 'OK', 'signature' => ''];
    }

    public function activationOtk(): array
    {
        return [
            'status' => 1,
            'message' => 'OK',
            'otk' => '000000',
            'uuid' => '00000000-0000-0000-0000-000000000000',
            'expired_at' => now()->addMinutes(10)->toIso8601String(),
        ];
    }

    public function garm(): array
    {
        return ['result' => 1];
    }

    /**
     * @param  array<string, scalar|null>|string  $values
     */
    private function formResponse(array|string $values): Response
    {
        $body = is_array($values) ? $this->forms->encode($values) : $values;

        return response($body, 200, [
            'Content-Length' => (string) strlen($body),
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }
}
