<?php

namespace App\Http\Middleware;

use App\Services\Localization;
use Closure;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function __construct(private readonly Localization $localization) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolve($request);

        App::setLocale($locale);

        View::share('locale', $locale);
        View::share('direction', $this->localization->direction($locale));

        return $next($request);
    }

    /**
     * Precedence: the signed-in user's stored preference, then their locale
     * cookie, then Accept-Language, then the configured fallback.
     *
     * Every candidate is checked against the supported allowlist by
     * `Localization::best()`. A locale becomes a path segment inside the
     * translation loader, so an unvalidated value is a traversal risk, not just
     * a cosmetic bug.
     *
     * The user branch keys off `HasLocalePreference` rather than a column, so
     * it starts working the moment the User model gains its `locale` field in
     * Domain Task 1 — no change needed here.
     */
    private function resolve(Request $request): string
    {
        $user = $request->user();
        $cookie = $request->cookie(Config::string('localization.cookie'));

        return $this->localization->best(
            $user instanceof HasLocalePreference ? $user->preferredLocale() : null,
            is_string($cookie) ? $cookie : null,
            ...$request->getLanguages(),
        );
    }
}
