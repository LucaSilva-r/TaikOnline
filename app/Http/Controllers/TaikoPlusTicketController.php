<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Short-lived ticket that admits a Taiko+ client to the realtime relay
 * (docker/taikoplus-relay), which shares the signing secret.
 */
class TaikoPlusTicketController extends Controller
{
    private const TICKET_LIFETIME_SECONDS = 60;

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cabinet_id' => ['required', 'string', 'max:64'],
        ]);

        $secret = (string) config('services.taikoplus_relay.secret');
        if ($secret === '') {
            return response()->json(['message' => 'Relay unavailable'], 503);
        }

        $payload = $this->base64Url(json_encode([
            'cab' => $validated['cabinet_id'],
            'exp' => time() + self::TICKET_LIFETIME_SECONDS,
        ], JSON_THROW_ON_ERROR));
        $signature = $this->base64Url(hash_hmac('sha256', $payload, $secret, true));

        return response()->json(['ticket' => $payload.'.'.$signature]);
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
