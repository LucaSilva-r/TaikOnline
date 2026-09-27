<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

/** The Waddamburo apps signed in to the account (its 'wdb' tokens), each revocable. */
class DeviceController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('settings/Devices', [
            'devices' => $request->user()->tokens()
                ->latest('last_used_at')
                ->latest()
                ->get()
                ->filter(fn (PersonalAccessToken $token): bool => $token->can('wdb'))
                ->map(fn (PersonalAccessToken $token): array => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'created_at' => $token->created_at?->toIso8601String(),
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'expires_at' => $token->expires_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ]);
    }

    public function destroy(Request $request, int $device): RedirectResponse
    {
        $request->user()->tokens()->whereKey($device)->firstOrFail()->delete();

        return back();
    }
}
