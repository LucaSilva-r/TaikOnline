<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admins and moderators act on other players' accounts and plays: their own account must have confirmed
 * two-factor authentication before any staff page or action works. Without it they are sent to their
 * security settings to turn it on. Not enforced in the local environment.
 */
class EnsureStaffHasTwoFactor
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Local development only: staff test accounts need no authenticator.
        if (! app()->environment('local') && $user?->isStaff() && ! $user->hasEnabledTwoFactorAuthentication()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Staff accounts need two-factor authentication: turn it on here to use the staff pages.')]);

            return to_route('security.edit', ['taikoVersion' => $request->route('taikoVersion')]);
        }

        return $next($request);
    }
}
