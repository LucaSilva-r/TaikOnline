<?php

namespace App\Http\Middleware;

use App\Models\WdbCabinet;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A Waddamburo cabinet: a token an admin issued (WdbCabinet), until revoked. Waddamburo's routes only:
 * the Zucchini cabinets' tokens (EnsureZucchiniApiToken) do not open them, nor these Zucchini's.
 */
class EnsureWaddamburoCabinet
{
    /** The request attribute naming the cabinet that sent it. */
    public const CABINET_ID = 'wdb_cabinet_id';

    public static function accepts(Request $request): bool
    {
        $token = $request->bearerToken();
        if ($token === null || ($cabinet = WdbCabinet::forToken($token)) === null) {
            return false;
        }
        $request->attributes->set(self::CABINET_ID, $cabinet->id);
        // ponytail: last seen within the minute (one write a minute per cabinet at most).
        if ($cabinet->last_seen_at === null || $cabinet->last_seen_at->lt(now()->subMinute())) {
            $cabinet->forceFill(['last_seen_at' => now()])->save();
        }

        return true;
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::accepts($request)) {
            return response('Unauthorized', 401, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return $next($request);
    }
}
