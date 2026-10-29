<?php

namespace App\Providers;

use App\Http\Middleware\ResolveTaikoVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
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
        $this->configureDefaults();

        // The moderation pages (mod/): admins and moderators.
        Gate::define('moderate', fn (User $user): bool => $user->isStaff());
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);
        URL::defaults(['taikoVersion' => ResolveTaikoVersion::DefaultScope]);

        RateLimiter::for('zucchini-cards', fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('zucchini-extra', fn (Request $request): Limit => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('zucchini-pairing', fn (Request $request): array => [
            Limit::perMinute(45)->by('cabinet:'.hash('sha256', (string) $request->input('cabinet_id'))),
            Limit::perMinute(600)->by('ip:'.$request->ip()),
        ]);
        RateLimiter::for('wdb-login', fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('wdb-device-poll', fn (Request $request): Limit => Limit::perMinute(40)->by($request->ip()));
        RateLimiter::for('wdb', fn (Request $request): Limit => Limit::perMinute(120)->by($request->bearerToken() ?? $request->ip()));
        // Reports of plays (game and website): a few at a time, a handful a day, per account.
        RateLimiter::for('play-reports', fn (Request $request): array => [
            Limit::perMinutes(10, 5)->by('play-reports:'.$request->user()?->id),
            Limit::perDay(20)->by('play-reports-day:'.$request->user()?->id),
        ]);
        RateLimiter::for('cabinet-login', fn (Request $request): array => [
            Limit::perMinute(10)->by('user:'.$request->user()?->id),
            Limit::perMinute(60)->by('ip:'.$request->ip()),
        ]);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
