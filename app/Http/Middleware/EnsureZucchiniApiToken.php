<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureZucchiniApiToken
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $hashes = collect(config('taiko_green.zucchini_api_token_hashes', []))
            ->map(fn (mixed $hash): string => trim((string) $hash))
            ->filter()
            ->values();

        if ($token === null || $hashes->isEmpty()) {
            return response('Unauthorized', 401, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $incoming = hash('sha256', $token);
        $valid = $hashes->contains(fn (string $hash): bool => hash_equals($hash, $incoming));

        if (! $valid) {
            return response('Unauthorized', 401, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return $next($request);
    }
}
