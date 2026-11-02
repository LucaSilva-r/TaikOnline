<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires a valid Cloudflare Turnstile token on the website's login, register and forgot-password submissions.
 * Only Fortify's web routes carry it, so the game APIs never see it. No TURNSTILE_SECRET_KEY: the check is off.
 */
class VerifyTurnstile
{
    /** @var list<string> */
    private const ProtectedRoutes = ['login.store', 'register.store', 'password.email'];

    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.turnstile.secret_key');

        if (blank($secret) || ! $request->routeIs(...self::ProtectedRoutes)) {
            return $next($request);
        }

        if (! $this->passes($secret, (string) $request->input('cf-turnstile-response'))) {
            throw ValidationException::withMessages([
                'turnstile' => 'The security check failed. Please try again.',
            ]);
        }

        return $next($request);
    }

    private function passes(string $secret, string $token): bool
    {
        if ($token === '') {
            return false;
        }

        try {
            return Http::asForm()->timeout(5)
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'secret' => $secret,
                    'response' => $token,
                ])
                ->json('success') === true;
        } catch (ConnectionException) {
            return false;
        }
    }
}
