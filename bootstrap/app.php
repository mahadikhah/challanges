<?php

use App\Actions\Observability\SendExceptionAlert;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('web')
                ->prefix('admin')
                ->name('admin.')
                ->group(base_path('routes/admin.php'));

            Route::group([], base_path('routes/telegram.php'));
            Route::group([], base_path('routes/bale.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // The bot webhook and the Mini App JSON API are stateless and must not
        // require a CSRF token. (Laravel 13 renamed the CSRF middleware to
        // PreventRequestForgery.)
        $middleware->preventRequestForgery(except: [
            'telegram/*',
            'bale/*',
            'api/*',
        ]);

        // Authorization outranks route-model binding. Without this, a
        // non-admin probing an admin URL learns from a 404 whether a row
        // exists before `EnsureUserIsAdmin` refuses them; with it, the refusal
        // comes first and every id reads the same to them.
        $middleware->priority([
            HandlePrecognitiveRequests::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            AuthenticatesRequests::class,
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
            EnsureUserIsAdmin::class,
            SubstituteBindings::class,
            Authorize::class,
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            SetLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Sanctum ships these middlewares but no longer registers the aliases
        // itself, and the Mini App API scopes its tokens by ability.
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);

        // The Mini App SPA reads its locale from the same resolver as the web
        // surfaces. SetLocale runs before HandleInertiaRequests above so the
        // shared props are built for the already-resolved locale.
        $middleware->api(append: [
            SetLocale::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Task 5's exception trigger. Runs *beside* the default file logging
        // (a reportable closure only replaces it by returning false); the
        // action itself debounces per exception class and no-ops entirely
        // when alerting is off or the ops chat is unconfigured.
        $exceptions->reportable(fn (Throwable $e) => app(SendExceptionAlert::class)->handle($e));
    })->create();
