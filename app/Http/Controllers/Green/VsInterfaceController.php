<?php

namespace App\Http\Controllers\Green;

use App\GameProtocol\Green\Proto\VsInterface\StartupAuthRequest;
use App\GameProtocol\Green\Proto\VsInterface\StartupAuthResponse;
use App\GameProtocol\Green\Proto\VsInterface\StartupAuthResponse\MovieData;
use App\GameProtocol\Green\Proto\VsInterface\StartupAuthResponse\OperationData;
use App\GameProtocol\Green\Proto\VsInterface\VerupAuthResponse;
use App\GameProtocol\Green\Proto\VsInterface\VerupCompleteResponse;
use App\GameProtocol\Green\Support\ProtocolPayloads;
use App\Http\Controllers\Controller;
use App\Models\Cabinet;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class VsInterfaceController extends Controller
{
    public function __construct(private readonly ProtocolPayloads $payloads) {}

    public function startupAuth(Request $request): Response
    {
        /** @var StartupAuthRequest $message */
        $message = $this->payloads->parse($request->getContent(), StartupAuthRequest::class);

        $serial = $message->getChassisId();
        $cabinet = $serial !== '' ? Cabinet::query()->whereKey($serial)->first() : null;

        if ($serial !== '' && $cabinet === null) {
            return $this->payloads->response((new StartupAuthResponse)->setResult(0));
        }

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
            $operations[] = (new OperationData)
                ->setKeyData((int) $entry['key'])
                ->setValueData(base64_decode($entry['value']));
        }

        return $this->payloads->response(
            (new StartupAuthResponse)
                ->setResult(1)
                ->setAryMovieInfo([
                    (new MovieData)
                        ->setMovieId(154)
                        ->setEnableDays(9999),
                ])
                ->setAryOperationInfo($operations)
        );
    }

    public function verupAuth(): Response
    {
        return $this->payloads->response((new VerupAuthResponse)->setResult(1));
    }

    public function verupComplete(): Response
    {
        return $this->payloads->response((new VerupCompleteResponse)->setResult(1));
    }
}
