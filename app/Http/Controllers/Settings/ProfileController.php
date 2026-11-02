<?php

namespace App\Http\Controllers\Settings;

use App\Enums\PresenceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\GameCard;
use App\Services\AccountDeletion;
use App\Services\WaddamburoStatus;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        $accessCode = GameCard::query()
            ->whereHas('player', fn ($query) => $query->where('user_id', $request->user()->id))
            ->value('access_code');

        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'accessCode' => $accessCode,
            // QR encodes the bare 20-digit code (the exact payload Zucchini's
            // camera reader accepts) so it can be scanned in lieu of a card.
            'accessCodeQr' => $accessCode !== null ? $this->accessCodeQrSvg($accessCode) : null,
        ]);
    }

    /**
     * Render the access code as an inline SVG QR (no imagick dependency).
     */
    private function accessCodeQrSvg(string $code): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(220, 1),
            new SvgImageBackEnd
        );

        $svg = (new Writer($renderer))->writeString($code);

        // Drop the XML prolog so the markup drops cleanly inline via {@html}.
        return preg_replace('/^<\?xml.*?\?>\s*/s', '', $svg) ?? $svg;
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Show or hide the user's online status, from now on.
     */
    public function updateOnlineStatus(Request $request, WaddamburoStatus $status): RedirectResponse
    {
        $validated = $request->validate(['presence_status' => ['required', Rule::enum(PresenceStatus::class)]]);
        $status->setPresenceStatus($request->user(), PresenceStatus::from($validated['presence_status']));

        // The user menu is on every page.
        return back();
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();

        Auth::logout();

        app(AccountDeletion::class)->delete($user);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
