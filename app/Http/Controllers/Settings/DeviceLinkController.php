<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\WaddamburoDeviceLoginService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The website side of Waddamburo's in-game login: approve (or refuse) the device whose code was entered on
 * the Play page, which sends sign-in codes here. Without a code, the Play page is where to enter one.
 */
class DeviceLinkController extends Controller
{
    public function create(Request $request, WaddamburoDeviceLoginService $devices): Response|RedirectResponse
    {
        $code = $request->string('code')->toString();
        if ($code === '') {
            return to_route('play.create', ['taikoVersion' => $request->route('taikoVersion')]);
        }
        $valid = preg_match('/\A[0-9]{6}\z/', $code) === 1;

        return Inertia::render('Link', [
            'hasPlayer' => $request->user()->player()->exists(),
            'code' => $valid ? $code : null,
            'device' => $valid ? $devices->pendingDevice($code) : null,
            'codeInvalid' => $code !== '' && ($valid ? $devices->pendingDevice($code) === null : true),
        ]);
    }

    public function store(Request $request, WaddamburoDeviceLoginService $devices): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/'],
            'approve' => ['required', 'boolean'],
        ]);

        if ($validated['approve'] && ! $request->user()->player()->exists()) {
            throw ValidationException::withMessages(['code' => __('Your account does not have a Banapass yet.')]);
        }
        if (! $devices->decide($validated['code'], $request->user(), (bool) $validated['approve'])) {
            throw ValidationException::withMessages(['code' => __('That code is invalid or has expired.')]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $validated['approve']
                ? __('Device linked: the game logs you in within a few seconds.')
                : __('Device refused.'),
        ]);

        return to_route('play.create', ['taikoVersion' => $request->route('taikoVersion')]);
    }
}
