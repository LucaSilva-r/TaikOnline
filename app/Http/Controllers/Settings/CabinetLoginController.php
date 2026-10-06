<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\CabinetLoginRequest;
use App\Services\CabinetPairingService;
use App\Services\WaddamburoDeviceLoginService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The site's one code page: a cabinet's code (any version's cabinet: Zucchini's or Waddamburo's) sends the
 * player's Banapass to it; a Waddamburo sign-in code goes on to its approval (DeviceLinkController).
 */
class CabinetLoginController extends Controller
{
    public function create(Request $request, WaddamburoDeviceLoginService $devices): Response|RedirectResponse
    {
        $code = $request->string('code')->toString();
        // A scanned Waddamburo sign-in code goes straight to its approval.
        if (preg_match('/\A[0-9]{6}\z/', $code) === 1 && $devices->pendingDevice($code) !== null) {
            return to_route('link.create', ['taikoVersion' => $request->route('taikoVersion'), 'code' => $code]);
        }

        return Inertia::render('Play', [
            'hasUsableAccessCode' => $this->accessCode($request) !== null,
            // A code from the cabinet's QR code (?code=), filled in for the player to confirm.
            'code' => preg_match('/\A[0-9]{6}\z/', $code) === 1 ? $code : null,
        ]);
    }

    public function store(CabinetLoginRequest $request, CabinetPairingService $pairings, WaddamburoDeviceLoginService $devices): RedirectResponse
    {
        $code = $request->validated('code');
        $accessCode = $this->accessCode($request);

        if ($accessCode === null || ! $pairings->claim($code, $accessCode)) {
            // Not a cabinet waiting for a card: a Waddamburo sign-in code goes on to its approval.
            if ($devices->pendingDevice($code) !== null) {
                return to_route('link.create', ['taikoVersion' => $request->route('taikoVersion'), 'code' => $code]);
            }
            throw ValidationException::withMessages([
                'code' => $accessCode === null
                    ? __('Your account does not have a usable Banapass code.')
                    : __('That code is invalid or has expired.'),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Your Banapass will be sent on the cabinet’s next poll.'),
        ]);

        return to_route('play.create');
    }

    private function accessCode(Request $request): ?string
    {
        $accessCode = $request->user()->player()->with('card')->first()?->card?->access_code;

        return is_string($accessCode) && preg_match('/\A[0-9]{20}\z/', $accessCode) === 1
            ? $accessCode
            : null;
    }
}
