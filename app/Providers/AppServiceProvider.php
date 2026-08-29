<?php

namespace App\Providers;

use App\Listeners\Ai\RecordAiProviderFailover;
use App\Listeners\Observability\AlertOnFailedJob;
use App\Models\User;
use App\Services\Ai\AiTextClient;
use App\Services\Ai\LaravelAiTextClient;
use App\Services\Localization;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\ProviderFailedOver;

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

        // One prompt seam for the whole app; the account rotation around it
        // stays in our consumer loop, not in this client.
        $this->app->bind(AiTextClient::class, LaravelAiTextClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->registerLogViewerGate();
        $this->registerAiFailoverListeners();
        $this->registerAlertListeners();
    }

    /**
     * The Log Viewer gate — the same check `EnsureUserIsAdmin` makes on every
     * admin-panel request and the Telescope gate makes on /telescope: the
     * authenticated user with `is_admin`. If these three ever diverge, one of
     * them is a bug.
     */
    protected function registerLogViewerGate(): void
    {
        Gate::define('viewLogViewer', fn (User $user) => $user->is_admin);
    }

    /**
     * Listeners resolve by EXACT class, not by parent: the provider-level and
     * agent-level failover events both need explicit registration, or roughly
     * half of real failures bench nothing — which looks exactly like "the
     * cooldown doesn't work".
     */
    protected function registerAiFailoverListeners(): void
    {
        Event::listen(ProviderFailedOver::class, RecordAiProviderFailover::class);
        Event::listen(AgentFailedOver::class, RecordAiProviderFailover::class);
    }

    /**
     * Task 5's failed-job trigger: Laravel fires `JobFailed` once a job has
     * exhausted its retries, and the listener turns that into an ops-chat
     * alert (or a silent no-op — every gate lives in the send action).
     */
    protected function registerAlertListeners(): void
    {
        Event::listen(JobFailed::class, AlertOnFailedJob::class);
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
