<?php

use App\Services\Localization;
use Illuminate\Support\Facades\Config;

it('exposes the supported locale codes', function () {
    expect(app(Localization::class)->codes())->toBe(['en', 'fa']);
});

it('accepts only allowlisted locales', function () {
    $localization = app(Localization::class);

    expect($localization->isSupported('en'))->toBeTrue()
        ->and($localization->isSupported('fa'))->toBeTrue()
        ->and($localization->isSupported('de'))->toBeFalse()
        ->and($localization->isSupported('../en'))->toBeFalse()
        ->and($localization->isSupported('EN'))->toBeFalse();
});

it('reports the reading direction of each locale', function () {
    $localization = app(Localization::class);

    expect($localization->direction('en'))->toBe('ltr')
        ->and($localization->direction('fa'))->toBe('rtl');
});

it('treats an unknown locale as left-to-right rather than failing', function () {
    expect(app(Localization::class)->direction('de'))->toBe('ltr');
});

it('falls back to the first supported locale when app.fallback_locale is misconfigured', function () {
    Config::set('app.fallback_locale', 'de');

    expect(app(Localization::class)->fallback())->toBe('en');
});

it('shapes the locales for a language picker', function () {
    expect(app(Localization::class)->options())->toBe([
        ['code' => 'en', 'native' => 'English', 'direction' => 'ltr'],
        ['code' => 'fa', 'native' => 'فارسی', 'direction' => 'rtl'],
    ]);
});

it('builds the payload for the active locale', function () {
    app()->setLocale('fa');

    expect(app(Localization::class)->payload())
        ->toHaveKeys(['locale', 'direction', 'locales', 'translations'])
        ->toMatchArray(['locale' => 'fa', 'direction' => 'rtl']);
});

it('never emits an unsupported locale in the payload', function () {
    expect(app(Localization::class)->payload('de'))
        ->toMatchArray(['locale' => 'en', 'direction' => 'ltr']);
});

it('flattens nested translation groups into dotted keys', function () {
    expect(app(Localization::class)->clientCatalog('en'))
        ->toHaveKey('common.app_name', 'Challenges')
        ->toHaveKey('common.actions.save', 'Save');
});

it('backfills the fallback locale so an untranslated key never renders as a raw key', function () {
    /*
    | The Farsi catalogue is the English one with Farsi merged over it, so it can
    | never be missing a key — a forgotten line degrades to English wording.
    */
    $localization = app(Localization::class);

    expect(array_keys($localization->clientCatalog('fa')))
        ->toEqualCanonicalizing(array_keys($localization->clientCatalog('en')));
});

it('ships only the configured client groups, keeping server-only copy off the wire', function () {
    Config::set('localization.client_groups', ['common']);

    $groups = collect(array_keys(app(Localization::class)->clientCatalog('en')))
        ->map(fn (string $key): string => str($key)->before('.')->value())
        ->unique()
        ->values();

    expect($groups->all())->toBe(['common']);
});

it('carries placeholders through to the client untouched', function () {
    expect(app(Localization::class)->clientCatalog('en'))
        ->toHaveKey('common.greeting', 'Hello, :name!');
});

it('translates and replaces placeholders server-side for the bot and mail', function () {
    app()->setLocale('fa');

    expect(__('common.greeting', ['name' => 'Ali']))->toBe('سلام، Ali!');
});
