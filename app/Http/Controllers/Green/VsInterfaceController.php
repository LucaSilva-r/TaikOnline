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
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class VsInterfaceController extends Controller
{
    public function __construct(private readonly ProtocolPayloads $payloads) {}

    public function startupAuth(Request $request): Response
    {
        /** @var StartupAuthRequest $message */
        $message = $this->payloads->parse($request->getContent(), StartupAuthRequest::class);

        $operations = [];
        foreach ($message->getAryOperationInfo() as $operation) {
            $operations[] = (new OperationData)
                ->setKeyData($operation->getKeyData())
                ->setValueData($operation->getValueData());
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
