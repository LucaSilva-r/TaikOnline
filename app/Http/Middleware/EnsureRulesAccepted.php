<?php

namespace App\Http\Middleware;

use App\Http\Controllers\LegalController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Sends a logged-in player to the Rules until they accept them, or log out or delete their account. */
class EnsureRulesAccepted
{
    /** What stays reachable: reading and accepting, leaving, and what confirms an email address. */
    private const ALLOWED_ROUTES = ['legal.*', 'logout', 'profile.destroy', 'verification.*'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null || ! LegalController::mustAccept($user) || $request->routeIs(...self::ALLOWED_ROUTES)
            || $request->is('broadcasting/auth')) {
            return $next($request);
        }
        if ($request->expectsJson() && ! $request->inertia()) {
            abort(403, 'Accept the Rules first.');
        }

        return redirect()->guest(route('legal.rules', ['taikoVersion' => LegalController::scope($request)]));
    }
}
