<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Waddamburo clients are either a cabinet an admin let in (its WdbCabinet token; plays name their
 * baid, learned through 6-pin pairing) or a player logged in at home (a Sanctum token with the
 * 'wdb' ability; plays belong to that user's player). Zucchini cabinets' tokens do not count.
 */
class AuthenticateWaddamburo
{
    public const CABINET = 'wdb_cabinet';

    public function handle(Request $request, Closure $next): Response
    {
        if (EnsureWaddamburoCabinet::accepts($request)) {
            $request->attributes->set(self::CABINET, true);

            return $next($request);
        }

        $user = Auth::guard('sanctum')->user();
        if ($user === null || ! $user->tokenCan('wdb')) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        Auth::setUser($user);

        return $next($request);
    }
}
