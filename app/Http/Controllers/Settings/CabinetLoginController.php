<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\CabinetLoginRequest;
use App\Services\CabinetPairingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CabinetLoginController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('Play', [
            'hasUsableAccessCode' => $this->accessCode($request) !== null,
        ]);
    }

    public function store(CabinetLoginRequest $request, CabinetPairingService $pairings): RedirectResponse
    {
        $accessCode = $this->accessCode($request);

        if ($accessCode === null) {
            throw ValidationException::withMessages([
                'code' => __('Your account does not have a usable Banapass code.'),
            ]);
        }

        if (! $pairings->claim($request->validated('code'), $accessCode)) {
            throw ValidationException::withMessages([
                'code' => __('That cabinet code is invalid or has expired.'),
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
