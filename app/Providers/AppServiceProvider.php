<?php

namespace App\Providers;

use App\Enums\TaikoGameVersion;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);
        URL::defaults(['taikoVersion' => TaikoGameVersion::default()->value]);

        RateLimiter::for('zucchini-cards', fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('zucchini-extra', fn (Request $request): Limit => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('zucchini-pairing', fn (Request $request): array => [
            Limit::perMinute(45)->by('cabinet:'.hash('sha256', (string) $request->input('cabinet_id'))),
            Limit::perMinute(600)->by('ip:'.$request->ip()),
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
