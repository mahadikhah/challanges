<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/*
 * The marketing landing. It is the one page a total stranger sees, so the
 * bar here is: it renders for guests, it carries the bot link the moment the
 * box knows one, and it stays up when the bot is not configured yet.
 */

it('renders the landing for a guest', function (): void {
    config(['services.telegram.bot_username' => 'ChallengesBot']);

    $this->get('/')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('welcome')
            ->where('bot_url', 'https://t.me/ChallengesBot'),
    );
});

it('omits the bot link when no bot username is configured', function (): void {
    config(['services.telegram.bot_username' => null]);

    $this->get('/')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('welcome')
            ->where('bot_url', null),
    );
});

it('does not treat an empty bot username as a configured one', function (): void {
    config(['services.telegram.bot_username' => '']);

    $this->get('/')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('bot_url', null),
    );
});

it('serves the landing with the website translation group in both locales', function (string $locale): void {
    config(['services.telegram.bot_username' => 'ChallengesBot']);

    // The locale a visitor gets is negotiated per request (user preference,
    // cookie, Accept-Language), not whatever the app was last set to.
    $this->withHeaders(['Accept-Language' => $locale])
        ->get('/')
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('locale', $locale)
                ->where('direction', $locale === 'fa' ? 'rtl' : 'ltr')
                // `translations` is a flat `group.key` map, so the value is
                // checked on the whole prop rather than by dotted descent
                // (which would read `translations -> website -> title`).
                ->where(
                    'translations',
                    fn (Collection $translations): bool => $translations->get('website.title') === __('website.title', [], $locale),
                ),
        );
})->with(['en', 'fa']);

it('still renders for a signed-in user', function (): void {
    config(['services.telegram.bot_username' => 'ChallengesBot']);

    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('welcome'));
});

/*
 * Every string the redesigned landing renders — the showcase cards and the
 * footer doc links — must ship in both locales in the same commit, or the
 * page would fall back to raw keys for half its visitors.
 */
it('ships every landing string in both locales', function (string $key): void {
    config(['services.telegram.bot_username' => 'ChallengesBot']);

    foreach (['en', 'fa'] as $locale) {
        $this->withHeaders(['Accept-Language' => $locale])
            ->get('/')
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page->where(
                    'translations',
                    fn (Collection $translations): bool => is_string($translations->get($key))
                        && $translations->get($key) !== ''
                        && $translations->get($key) !== $key,
                ),
            );
    }
})->with([
    'website.showcase.title',
    'website.showcase.note',
    'website.showcase.streak',
    'website.showcase.freezes_left',
    'website.showcase.morning.title',
    'website.showcase.morning.meta',
    'website.showcase.morning.progress',
    'website.showcase.reading.title',
    'website.showcase.reading.meta',
    'website.showcase.reading.progress',
    'website.showcase.language.title',
    'website.showcase.language.meta',
    'website.showcase.language.progress',
    'website.docs.readme',
    'website.docs.setup_vps',
    'website.docs.setup_cpanel',
    'website.docs.user_flows',
]);

it('lands without admin chrome', function (): void {
    config(['services.telegram.bot_username' => 'ChallengesBot']);

    $html = $this->get('/')->assertOk()->getContent();

    // The landing is the one chrome-free surface: if the sidebar ever leaks
    // in through a layout or shared component, a stranger's first page would
    // carry the admin panel's furniture.
    expect($html)
        ->not->toContain('data-sidebar')
        ->not->toContain('data-slot="sidebar"');
});
