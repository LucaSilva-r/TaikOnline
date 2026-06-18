<?php

namespace App\Http\Controllers\Green;

use App\GameProtocol\Support\FormPayloads;
use App\GameProtocol\Support\MuchaCrypto;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AllNetController extends Controller
{
    /**
     * Token wallet handed to international Mucha cabinets. The value itself is
     * not metered locally; it only needs to be non-zero so the cabinet's token
     * state machine reaches its operational state. Mirrors TaikoLocalServer.
     */
    private const MUCHA_TOKEN_GRANT = '999';

    public function __construct(
        private readonly FormPayloads $forms,
        private readonly MuchaCrypto $muchaCrypto,
    ) {}

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
        $countryCode = (string) ($request->input('countryCd') ?: config('taiko_green.country'));

        // International Taiko Red parses the board-auth service URLs as bare
        // "host:port" and routes its game requests to them. A scheme or path
        // (e.g. "https://host:port/charge/") breaks that parser, leaving the
        // cabinet unable to reach the game server (GAME SERVER NG). Emit the
        // reachable endpoint as host:port only, matching the live Mucha front.
        $muchaHostPort = $this->hostPort((string) config('taiko_green.mucha_game_url'));

        // The dongle PRX slices SERVER_TIME as fixed-width YYYYMMDDHHMMSS
        // (offsets 0/4/6/8/10/12, each len 2 after the year). A 12-char
        // YmdHi string leaves the final substr(12,2) empty -> std::stoi
        // throws -> MuchaMainThread aborts. Must be 14-char YmdHis.
        $serverTime = now()->format('YmdHis');

        return $this->formResponse([
            'RESULTS' => '001',
            'AREA_0' => '008',
            'AREA_0_EN' => '008',
            'AREA_1' => '009',
            'AREA_1_EN' => '009',
            'AREA_2' => '010',
            'AREA_2_EN' => '010',
            'AREA_3' => '011',
            'AREA_3_EN' => '011',
            'AREA_FULL_0' => '',
            'AREA_FULL_0_EN' => '',
            'AREA_FULL_1' => '',
            'AREA_FULL_1_EN' => '',
            'AREA_FULL_2' => '',
            'AREA_FULL_2_EN' => '',
            'AREA_FULL_3' => '',
            'AREA_FULL_3_EN' => '',
            'AUTH_INTERVAL' => '86400',
            'CHARGE_URL' => $muchaHostPort,
            'COUNTRY_CD' => $countryCode,
            'DONGLE_FLG' => '1',
            'EXPIRATION_DATE' => '20500613',
            'FILE_URL' => $muchaHostPort,
            'FORCE_BOOT' => '0',
            'PLACE_ID' => $placeId,
            'PREFECTURE_ID' => '14',
            'SERVER_TIME' => $serverTime,
            'SERVER_TIME_UTC' => now('UTC')->format('YmdHis'),
            'SHOP_NAME' => (string) config('taiko_green.shop_name'),
            'SHOP_NAME_EN' => (string) config('taiko_green.shop_name'),
            'SHOP_NICKNAME' => 'W',
            'SHOP_NICKNAME_EN' => 'W',
            'URL_1' => $muchaHostPort,
            'URL_2' => $muchaHostPort,
            'URL_3' => $muchaHostPort,
            'USE_TOKEN' => '1',
            'CONSUME_TOKEN' => '1',
        ]);
    }

    public function regiAuth(Request $request): Response
    {
        Log::channel('mucha')->info('regiauth', [
            'game_cd' => $request->input('gameCd'),
            'serial_num' => $request->input('serialNum'),
            'country_cd' => $request->input('countryCd'),
            'place_id' => $request->input('placeId'),
            'use_token' => $request->input('useToken'),
            'all_token' => $request->input('allToken'),
        ]);

        $tokenKey = $this->muchaCrypto->tokenKey($request->input('sendDate'));

        // International Red gates its operational state (network-icon green,
        // card reader enabled) on a valid Mucha token, not on the count the
        // cabinet reports. Hand back a full wallet so the token state machine
        // validates, matching TaikoLocalServer's fixed "999" grant.
        return $this->formResponse([
            'RESULTS' => '001',
            'ALL_TOKEN' => $this->muchaCrypto->encryptToken(self::MUCHA_TOKEN_GRANT, $tokenKey),
            'ADD_TOKEN' => $this->muchaCrypto->encryptToken(self::MUCHA_TOKEN_GRANT, $tokenKey),
        ]);
    }

    public function tokenState(Request $request): Response
    {
        Log::channel('mucha')->info('tokenstate', [
            'game_cd' => $request->input('gameCd'),
            'serial_num' => $request->input('serialNum'),
            'country_cd' => $request->input('countryCd'),
            'place_id' => $request->input('placeId'),
            'use_token' => $request->input('useToken'),
            'all_token' => $request->input('allToken'),
        ]);

        $tokenKey = $this->muchaCrypto->tokenKey($request->input('sendDate'));

        return $this->formResponse([
            'RESULTS' => '001',
            'ALL_TOKEN' => $this->muchaCrypto->encryptToken(self::MUCHA_TOKEN_GRANT, $tokenKey),
            'ADD_TOKEN' => $this->muchaCrypto->encryptToken(self::MUCHA_TOKEN_GRANT, $tokenKey),
        ]);
    }

    public function updateCheck(Request $request): Response
    {
        $gameVersion = (string) ($request->input('gameVer') ?? $request->input('game_ver') ?? $request->input('gameVersion') ?? 'S1110JPN13.02');
        $muchaGameUrl = (string) config('taiko_green.mucha_game_url');

        $forced = (bool) config('taiko_green.mucha_force_update');
        $advertisedVer = $forced
            ? (string) config('taiko_green.mucha_forced_target_ver')
            : $gameVersion;

        $chunkSize = '0';
        if ($forced) {
            $path = (string) config('taiko_green.mucha_chunk_path');
            if (is_file($path)) {
                $chunkSize = (string) filesize($path);
            }
        }

        Log::channel('mucha')->info('updatacheck', [
            'cab_ver' => $gameVersion,
            'advertised_ver' => $advertisedVer,
            'forced' => $forced,
            'chunk_size' => $chunkSize,
            'request' => $request->all(),
        ]);

        // Cabinet downloads + decrypts UPDATE_URL_1 when UPDATE_SIZE_1 > 0,
        // then verifies signature/CRC. Without Namco's signing key the
        // decrypt always fails -> error 5-36 (UPDATE SERVER AUTH SIGNATURE).
        // Setting size to 0 (when not forced) makes the cabinet skip the
        // download/verify path entirely.
        $response = [
            'RESULTS' => '001',
            'UPDATE_URL_1' => $muchaGameUrl.'/updUrl1/',
            'UPDATE_SIZE_1' => $chunkSize !== '0' ? $chunkSize : '0',
            'UPDATE_CRC_1' => '00000000',
            'EXE_VER_1' => $advertisedVer,
            'INFO_SIZE_1' => '0',
            'COM_SIZE_1' => '0',
            'COM_TIME_1' => '0',
            'LAN_INFO_SIZE_1' => '0',
            'USER_ID' => '1',
            'PASSWORD' => '1',
            'EXE_VER' => $advertisedVer,
        ];

        if ($forced) {
            $response['CHECK_URL_1'] = $muchaGameUrl.'/checkUrl/';
            $response['CHECK_SIZE_1'] = $chunkSize;
            $response['CHECK_CRC_1'] = '00000000';
        }

        return $this->formResponse($response);
    }

    public function muchaChunkImage(Request $request): BinaryFileResponse|Response
    {
        $path = (string) config('taiko_green.mucha_chunk_path');

        Log::channel('mucha')->info('chunk_download_request', [
            'path' => $path,
            'exists' => is_file($path),
            'size' => is_file($path) ? filesize($path) : 0,
            'range' => $request->header('Range'),
            'user_agent' => $request->userAgent(),
            'client_ip' => $request->ip(),
        ]);

        if (! is_file($path)) {
            return response('', 404, ['Content-Type' => 'application/octet-stream']);
        }

        return response()->file($path, [
            'Content-Type' => 'application/octet-stream',
        ]);
    }

    public function muchaDownloadState(Request $request): Response
    {
        Log::channel('mucha')->info('downloadstate', $request->all());

        return $this->formResponse(['RESULTS' => '001']);
    }

    public function muchaDownloadError(Request $request): Response
    {
        Log::channel('mucha')->warning('downloaderror', $request->all());

        return $this->formResponse(['RESULTS' => '001']);
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

    /**
     * Reduce a configured URL to bare "host:port" (dropping scheme and path),
     * the form international Taiko Red's board-auth parser expects.
     */
    private function hostPort(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return rtrim(preg_replace('#^[a-z]+://#i', '', $url) ?? $url, '/');
        }

        return isset($parts['port']) ? "{$parts['host']}:{$parts['port']}" : $parts['host'];
    }
}
