<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Enums\TaikoGameVersion;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);

        Fortify::authenticateUsing(function (Request $request): ?User {
            $login = (string) $request->input(Fortify::username());

            $user = User::query()
                ->where('username', Str::lower($login))
                ->orWhere('email', Str::lower($login))
                ->first();

            if ($user && Hash::check($request->input('password'), $user->password)) {
                return $user;
            }

            return null;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(function (Request $request) {
            // When the dongle deep-link (/{version}/settings/profile?access_code=)
            // bounced an unauthenticated user here, surface the pending code so
            // the "Sign up" link can carry it through to registration. Logging
            // in needs nothing extra — the intended URL already round-trips.
            [$version, $code] = $this->intendedAccessCode($request);

            return Inertia::render('auth/Login', [
                'canResetPassword' => Features::enabled(Features::resetPasswords()),
                'canRegister' => Features::enabled(Features::registration()),
                'status' => $request->session()->get('status'),
                'signupAccessCode' => $code,
                'signupVersion' => $code !== null ? $version : null,
                'playIntent' => $this->intendedForPlay($request),
            ]);
        });

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(function (Request $request) {
            // Carry an access code arriving from the login "Sign up" link into
            // the post-registration redirect: after the account is created,
            // Fortify's RegisterResponse honours the intended URL, dropping the
            // new user straight onto the prefilled access-code bind form.
            $code = $this->validAccessCode((string) $request->query('access_code', ''));
            if ($code !== null) {
                // Land the new account on the profile page after registration so
                // they can see the card is now linked.
                $version = $this->sanitizeVersion((string) $request->query('v', ''));
                redirect()->setIntendedUrl(route('profile.edit', ['taikoVersion' => $version]));
            }

            return Inertia::render('auth/Register', [
                'accessCode' => $code,
            ]);
        });

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Pull the [version, access_code] the user was bounced from, if any, out
     * of the intended URL stored by the auth middleware.
     *
     * @return array{0: string, 1: ?string}
     */
    private function intendedAccessCode(Request $request): array
    {
        $intended = (string) $request->session()->get('url.intended', '');
        if ($intended === '') {
            return ['green', null];
        }

        $path = (string) (parse_url($intended, PHP_URL_PATH) ?? '');
        $version = $this->sanitizeVersion(explode('/', ltrim($path, '/'))[0] ?? '');

        $query = (string) (parse_url($intended, PHP_URL_QUERY) ?? '');
        parse_str($query, $params);

        return [$version, $this->validAccessCode((string) ($params['access_code'] ?? ''))];
    }

    private function validAccessCode(string $value): ?string
    {
        return preg_match('/^\d{20}$/', $value) === 1 ? $value : null;
    }

    private function intendedForPlay(Request $request): bool
    {
        $intended = (string) $request->session()->get('url.intended', '');
        $path = (string) (parse_url($intended, PHP_URL_PATH) ?? '');
        $segments = explode('/', trim($path, '/'));
        $supportedScopes = [
            ...array_map(
                fn (TaikoGameVersion $version): string => $version->value,
                TaikoGameVersion::cases(),
            ),
            'extra',
            'all',
        ];

        return count($segments) === 2
            && in_array($segments[0], $supportedScopes, true)
            && $segments[1] === 'play';
    }

    private function sanitizeVersion(string $value): string
    {
        $value = preg_replace('/[^a-z]/', '', Str::lower($value)) ?? '';

        return $value !== '' ? $value : 'green';
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}
