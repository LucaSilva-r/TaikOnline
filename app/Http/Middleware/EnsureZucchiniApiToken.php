<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureZucchiniApiToken
{
    public static function accepts(Request $request): bool
    {
        $token = $request->bearerToken();
        $hashes = collect(config('taiko_green.zucchini_api_token_hashes', []))
            ->map(fn (mixed $hash): string => trim((string) $hash))
            ->filter()
            ->values();

        if ($token === null || $hashes->isEmpty()) {
            return false;
        }

        $incoming = hash('sha256', $token);

        return $hashes->contains(fn (string $hash): bool => hash_equals($hash, $incoming));
    }

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! self::accepts($request)) {
            return response('Unauthorized', 401, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return $next($request);
    }
}
