<?php

namespace App\Http\Controllers\Green;

use App\Enums\TaikoGameVersion;
use App\GameProtocol\Support\MessageWriter;
use App\GameProtocol\Support\ProtocolMessageResolver;
use App\GameProtocol\Support\ProtocolPayloads;
use App\Http\Controllers\Controller;
use App\Models\Cabinet;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;

class VsInterfaceController extends Controller
{
    public function __construct(
        private readonly ProtocolPayloads $payloads,
        private readonly ProtocolMessageResolver $messages,
        private readonly MessageWriter $writer,
    ) {}

    public function startupAuth(Request $request, string $version): Response
    {
        $game = $this->version($version);
        $message = $this->payloads->parse(
            $request->getContent(),
            $this->messages->class($game, 'StartupAuthRequest', 'VsInterface'),
        );

        $serial = $message->getChassisId();
        $cabinet = $serial !== '' ? Cabinet::query()->whereKey($serial)->first() : null;

        $reported = [];
        foreach ($message->getAryOperationInfo() as $operation) {
            $reported[] = [
                'key' => $operation->getKeyData(),
                'value' => base64_encode($operation->getValueData()),
            ];
        }

        if ($cabinet !== null) {
            $cabinet->update([
                'reported_config' => $reported,
                'reported_meta' => [
                    'shop_id' => $message->getShopId(),
                    'rack_id' => $message->getRackId(),
                    'country_id' => $message->getCountryId(),
                    'hdd_ver' => $message->getHddVer(),
                    'usbmem_ver' => $message->getUsbmemVer(),
                    'usbmem_key' => $message->getUsbmemKey(),
                ],
                'last_reported_at' => now(),
            ]);
        }

        $payload = $cabinet?->desired_config ?? $reported;

        $operations = [];
        foreach ($payload as $entry) {
            $operations[] = $this->writer->fill(
                $this->messages->make($game, 'StartupAuthResponse\\OperationData', 'VsInterface'),
                [
                    'setKeyData' => (int) $entry['key'],
                    'setValueData' => base64_decode($entry['value']),
                ],
            );
        }

        $response = $this->writer->fill(
            $this->messages->make($game, 'StartupAuthResponse', 'VsInterface'),
            [
                'setResult' => 1,
                'setAryOperationInfo' => $operations,
            ],
        );

        $movieId = $this->startupMovieId($message->getHddVer());
        if ($movieId !== null) {
            $this->writer->set($response, 'setAryMovieInfo', [
                $this->writer->fill(
                    $this->messages->make($game, 'StartupAuthResponse\\MovieData', 'VsInterface'),
                    [
                        'setMovieId' => $movieId,
                        'setEnableDays' => 9999,
                    ],
                ),
            ]);
        }

        return $this->payloads->response($response);
    }

    public function verupAuth(Request $request, string $version): Response
    {
        $game = $this->version($version);

        return $this->payloads->response(
            $this->writer->set($this->messages->make($game, 'VerupAuthResponse', 'VsInterface'), 'setResult', 1)
        );
    }

    public function verupComplete(Request $request, string $version): Response
    {
        $game = $this->version($version);

        return $this->payloads->response(
            $this->writer->set($this->messages->make($game, 'VerupCompleteResponse', 'VsInterface'), 'setResult', 1)
        );
    }

    private function version(string $routeVersion): TaikoGameVersion
    {
        $normalized = strtolower(trim($routeVersion));
        $major = preg_match('/^(v\d{2})/', $normalized, $matches) === 1 ? $matches[1] : null;
        $catalogVersion = Config::get("taiko_green.route_catalog_versions.{$normalized}");

        if ($catalogVersion === null && $major !== null) {
            $catalogVersion = Config::get("taiko_green.route_catalog_versions.{$major}");
        }

        if (is_string($catalogVersion)) {
            $version = TaikoGameVersion::fromInput($catalogVersion);

            if ($version instanceof TaikoGameVersion) {
                return $version;
            }
        }

        return TaikoGameVersion::fromRouteVersion($routeVersion) ?? TaikoGameVersion::Green;
    }

    private function startupMovieId(int $hddVersion): ?int
    {
        $configured = Config::get("taiko_green.startup_movie_ids.{$hddVersion}");

        if (! is_int($configured)) {
            $majorVersion = sprintf('v%02d', intdiv($hddVersion, 100));
            $configured = Config::get("taiko_green.startup_movie_ids.{$majorVersion}");
        }

        return is_int($configured) ? $configured : null;
    }
}
