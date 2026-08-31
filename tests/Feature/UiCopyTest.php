<?php

use App\Services\Localization;
use Illuminate\Support\Arr;

/*

| The starter-kit sweep. Phase 16 Part A routed every user-facing string in
| the files below through the i18n layer; this test is the tripwire that
| keeps them clean. A hardcoded English literal reintroduced into an audited
| file fails the build with the offending file:line:match.
|
| The scan is deliberately narrow: only the audited files, only two shapes
| of literal (JSX text children and copy-bearing string props), ≥2 English
| words, and an explicit allowlist for non-UI strings (attributes, URLs,
| technical tokens). It is not a general lint — it guards this sweep.

*/

/**
 * Files the Phase 16 Part A sweep covered. Anything user-facing added here
| must go through `t()` with en+fa keys.
 */
function uiCopyAuditedFiles(): array
{
    $base = 'resources/js/';

    return array_map(
        fn (string $path): string => base_path($base.$path),
        [
            'components/alert-error.tsx',
            'components/app-header.tsx',
            'components/app-sidebar.tsx',
            'components/appearance-tabs.tsx',
            'components/breadcrumbs.tsx',
            'components/delete-user.tsx',
            'components/nav-main.tsx',
            'components/password-input.tsx',
            'components/user-menu-content.tsx',
            'layouts/auth-layout.tsx',
            'layouts/auth/auth-split-layout.tsx',
            'layouts/settings/layout.tsx',
            'pages/auth/confirm-password.tsx',
            'pages/auth/forgot-password.tsx',
            'pages/auth/login.tsx',
            'pages/auth/register.tsx',
            'pages/auth/reset-password.tsx',
            'pages/auth/verify-email.tsx',
            'pages/dashboard.tsx',
            'pages/settings/appearance.tsx',
            'pages/settings/profile.tsx',
            'pages/settings/security.tsx',
        ],
    );
}

/**
 * Strip // line comments and /* block comments so commented-out copy does
 * not trip the scan.
 */
function uiCopyStripComments(string $source): string
{
    $withoutBlocks = preg_replace('/\/\*.*?\*\//s', '', $source) ?? $source;

    return preg_replace('/\/\/.*$/m', '', $withoutBlocks) ?? $withoutBlocks;
}

/**
 * Find English-looking literals: ≥2 words of letters, each starting with a
 * letter, allowing apostrophes and trailing punctuation. Single words are
 * ignored — they are overwhelmingly code identifiers (variant names, data
 * attributes, enum values).
 */
function uiCopyOffenders(string $path): array
{
    $source = uiCopyStripComments((string) file_get_contents($path));
    $lines = explode("\n", $source);

    $word = "[A-Za-z][A-Za-z''’]*";
    $phrase = "{$word}(?:\s+{$word})+";

    $offenders = [];

    foreach ($lines as $index => $line) {
        $lineNumber = $index + 1;

        // JSX text children between tags: >Words here<
        if (preg_match_all("/>\s*({$phrase})\s*</u", $line, $matches)) {
            foreach ($matches[1] as $match) {
                $offenders[] = "{$path}:{$lineNumber}: {$match}";
            }
        }

        // Copy-bearing props assigned a string literal — a t() call is a
        // {…} expression, not a quoted literal, so it never matches.
        // Object keys (`title:` in static .layout objects with an English
        // fallback beside a titleKey) use a colon and are likewise exempt.
        $copyProps = 'placeholder|title|label|description|alt|content';
        if (
            preg_match_all(
                "/\b(?:{$copyProps})\s*=\s*[\"']({$phrase})[\"']/u",
                $line,
                $matches,
            )
        ) {
            foreach ($matches[1] as $match) {
                $offenders[] = "{$path}:{$lineNumber}: {$match}";
            }
        }
    }

    return $offenders;
}

describe('UI copy sweep', function (): void {
    test('no hardcoded English copy remains in the audited starter-kit files', function (): void {
        $offenders = [];

        foreach (uiCopyAuditedFiles() as $path) {
            expect(file_exists($path))->toBeTrue("Missing audited file: {$path}");

            $offenders = [...$offenders, ...uiCopyOffenders($path)];
        }

        expect($offenders)->toBeEmpty(
            "Hardcoded English copy found — route it through t() with en+fa keys:\n".
            implode("\n", $offenders),
        );
    });

    test('every client translation group is en/fa complete', function (string $group): void {
        $en = Arr::dot(require base_path("lang/en/{$group}.php"));
        $fa = Arr::dot(require base_path("lang/fa/{$group}.php"));

        expect(array_keys($fa))->toEqualCanonicalizing(array_keys($en))
            ->and(array_keys($en))->not->toBeEmpty();
    })->with(['common', 'enums', 'miniapp', 'admin', 'website', 'auth', 'settings']);

    test('the new groups are shipped to the browser in both locales', function (string $locale): void {
        $payload = app(Localization::class)->payload($locale);
        $translations = $payload['translations'];

        // A missing key would render as the raw key string in the UI.
        expect($translations['auth.login.title'])
            ->toBe(__('auth.login.title', [], $locale))
            ->and($translations['settings.title'])
            ->toBe(__('settings.title', [], $locale))
            ->and($translations['common.user_menu.logout'])
            ->toBe(__('common.user_menu.logout', [], $locale));
    })->with(['en', 'fa']);
});
