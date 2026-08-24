<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;

/**
 * Everything the platform knows about the locales it serves: which ones are
 * allowed, which way they read, and the flattened catalogue handed to the two
 * frontends.
 *
 * The single source of truth for every string is `lang/{locale}/*.php`, so the
 * bot (via `__()`), the Inertia surfaces and the Mini App SPA cannot drift
 * apart — there is no second, JS-side catalogue to keep in sync.
 */
class Localization
{
    /**
     * Per-request memoised client catalogues, keyed by locale.
     *
     * @var array<string, array<string, string>>
     */
    private array $catalogs = [];

    /**
     * The locale codes this platform is allowed to serve.
     *
     * @return list<string>
     */
    public function codes(): array
    {
        return array_map(
            static fn (int|string $code): string => (string) $code,
            array_keys(Config::array('localization.supported')),
        );
    }

    public function isSupported(string $locale): bool
    {
        return in_array($locale, $this->codes(), true);
    }

    /**
     * The locale to fall back to when nothing else resolves. Guarded against a
     * misconfigured `app.fallback_locale` that isn't in the allowlist.
     */
    public function fallback(): string
    {
        $fallback = Config::string('app.fallback_locale');

        if ($this->isSupported($fallback)) {
            return $fallback;
        }

        return $this->codes()[0] ?? 'en';
    }

    /**
     * Reading direction — `ltr` or `rtl`.
     */
    public function direction(string $locale): string
    {
        return Config::string("localization.supported.{$locale}.direction", 'ltr');
    }

    /**
     * What a speaker of the language calls it.
     */
    public function nativeName(string $locale): string
    {
        return Config::string("localization.supported.{$locale}.native", $locale);
    }

    /**
     * The switchable locales, shaped for a language picker.
     *
     * @return list<array{code: string, native: string, direction: string}>
     */
    public function options(): array
    {
        return array_map(fn (string $code): array => [
            'code' => $code,
            'native' => $this->nativeName($code),
            'direction' => $this->direction($code),
        ], $this->codes());
    }

    /**
     * Everything a frontend needs to render in the active locale. Shared as
     * Inertia props on the admin/website surfaces and embedded in the Mini App
     * shell, so both consume an identical shape.
     *
     * @return array{locale: string, direction: string, locales: list<array{code: string, native: string, direction: string}>, translations: array<string, string>}
     */
    public function payload(?string $locale = null): array
    {
        $locale ??= app()->getLocale();

        if (! $this->isSupported($locale)) {
            $locale = $this->fallback();
        }

        return [
            'locale' => $locale,
            'direction' => $this->direction($locale),
            'locales' => $this->options(),
            'translations' => $this->clientCatalog($locale),
        ];
    }

    /**
     * The flattened `group.key => line` map shipped to the client, with the
     * fallback locale underneath so a not-yet-translated key degrades to the
     * fallback wording rather than showing a raw key.
     *
     * @return array<string, string>
     */
    public function clientCatalog(string $locale): array
    {
        if (! $this->isSupported($locale)) {
            $locale = $this->fallback();
        }

        return $this->catalogs[$locale] ??= array_merge(
            $this->loadGroups($this->fallback()),
            $this->loadGroups($locale),
        );
    }

    /**
     * Read and flatten the client-facing groups for one locale.
     *
     * Only ever called with an allowlisted locale — it interpolates into a
     * filesystem path that gets `require`d.
     *
     * @return array<string, string>
     */
    private function loadGroups(string $locale): array
    {
        $lines = [];

        foreach (Config::array('localization.client_groups') as $group) {
            $group = (string) $group;
            $path = lang_path("{$locale}/{$group}.php");

            if (! is_file($path)) {
                continue;
            }

            $translations = require $path;

            if (! is_array($translations)) {
                continue;
            }

            foreach (Arr::dot($translations) as $key => $line) {
                if (is_string($line) || is_int($line) || is_float($line)) {
                    $lines["{$group}.{$key}"] = (string) $line;
                }
            }
        }

        return $lines;
    }
}
