<?php

namespace App\Http\Controllers;

use App\Http\Requests\ZucchiniPairingRequest;
use App\Services\CabinetPairingService;
use Illuminate\Http\Response;
use RuntimeException;

class ZucchiniPairingController extends Controller
{
    public function __invoke(ZucchiniPairingRequest $request, CabinetPairingService $pairings): Response
    {
        try {
            $result = $pairings->poll(
                cabinetId: $request->validated('cabinet_id'),
                state: $request->validated('state'),
                accepting: $request->boolean('accepting'),
                sessionToken: $request->validated('session'),
                ackCommandId: $request->validated('ack'),
            );
        } catch (RuntimeException) {
            return response("unavailable\n", 503, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return response($this->encode($result), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * @param  array<string, int|string|null>  $result
     */
    private function encode(array $result): string
    {
        return collect($result)
            ->filter(fn (mixed $value): bool => $value !== null)
            ->map(fn (mixed $value, string $key): string => $key.'='.$value)
            ->implode("\n")."\n";
    }
}
