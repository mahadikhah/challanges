<?php

namespace App\Providers;

use App\Services\Localization;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
        | Both of these memoise per request, so they have to be shared instances
        | to be worth anything. Settings additionally *needs* to be a singleton:
        | a write through set() clears the memo on the instance it was called on,
        | and a second instance would keep serving the pre-write value.
        |
        | Localization is resolved from four places in a single request (the
        | middleware, HandleInertiaRequests, the Mini App route and the locale
        | Form Request); without this it re-reads and re-flattens the language
        | files each time.
        */
        $this->app->singleton(Settings::class);
        $this->app->singleton(Localization::class);
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
