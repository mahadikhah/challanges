<?php

use App\Models\User;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/*
| The locale becomes a path segment inside the translation loader, which
| `require`s the resulting file. So these tests care as much about what is
| *rejected* as about what resolves.
*/

describe('locale resolution', function () {
    it('falls back to the configured locale when the visitor expresses no preference', function () {
        $this->withoutVite()->get('/')->assertOk();

        expect(app()->getLocale())->toBe('en');
    });

    it('honours a supported locale cookie', function () {
        $this->withoutVite()->withCookie('locale', 'fa')->get('/')->assertOk();

        expect(app()->getLocale())->toBe('fa');
    });

    it('negotiates the locale from Accept-Language when no cookie is set', function () {
        $this->withoutVite()
            ->withHeader('Accept-Language', 'fa-IR,fa;q=0.9,en;q=0.8')
            ->get('/')
            ->assertOk();

        expect(app()->getLocale())->toBe('fa');
    });

    it('prefers an explicit cookie over Accept-Language', function () {
        $this->withoutVite()
            ->withCookie('locale', 'en')
            ->withHeader('Accept-Language', 'fa-IR,fa;q=0.9')
            ->get('/')
            ->assertOk();

        expect(app()->getLocale())->toBe('en');
    });

    it("prefers a signed-in user's stored preference over the cookie", function () {
        /*
        | Keyed off the HasLocalePreference contract rather than a column, so this
        | precedence is locked in before Domain Task 1 adds `User.locale`.
        */
        $user = new class extends User implements HasLocalePreference
        {
            protected $table = 'users';

            public function preferredLocale(): string
            {
                return 'fa';
            }
        };

        $this->withoutVite()
            ->actingAs($user)
            ->withCookie('locale', 'en')
            ->get('/')
            ->assertOk();

        expect(app()->getLocale())->toBe('fa');
    });

    it('ignores a locale that is not on the allowlist', function (string $cookie) {
        $this->withoutVite()->withCookie('locale', $cookie)->get('/')->assertOk();

        expect(app()->getLocale())->toBe('en');
    })->with([
        'unsupported language' => 'de',
        'relative traversal' => '../en',
        'deep traversal' => '../../../../etc/passwd',
        'absolute path' => '/etc/passwd',
        'empty string' => '',
    ]);
});

describe('document direction', function () {
    it('renders the document right-to-left for Farsi', function () {
        $this->withoutVite()->withCookie('locale', 'fa')->get('/')
            ->assertOk()
            ->assertSee('lang="fa"', false)
            ->assertSee('dir="rtl"', false);
    });

    it('renders the document left-to-right for English', function () {
        $this->withoutVite()->withCookie('locale', 'en')->get('/')
            ->assertOk()
            ->assertSee('lang="en"', false)
            ->assertSee('dir="ltr"', false);
    });
});

describe('shared props', function () {
    it('shares the localization payload with the Inertia surfaces', function () {
        $this->withoutVite()->withCookie('locale', 'fa')->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('locale', 'fa')
                ->where('direction', 'rtl')
                ->has('locales', 2)
                ->where('locales.0.code', 'en')
                ->where('locales.1.native', 'فارسی')
                ->where('locales.1.direction', 'rtl')
                // The catalogue is flat: `group.key` is one literal key, not a nested path.
                ->where('translations', fn (Collection $translations): bool => $translations['common.app_name'] === 'چالش‌ها'
                    && $translations['common.actions.save'] === 'ذخیره'));
    });
});

describe('switching locale', function () {
    it('remembers the chosen locale in a cookie and returns the visitor where they were', function () {
        $this->from('/dashboard')->post('/locale', ['locale' => 'fa'])
            ->assertRedirect('/dashboard')
            ->assertCookie('locale', 'fa');
    });

    it('is available to guests, because the public site must be readable before signing in', function () {
        $this->assertGuest();

        $this->from('/')->post('/locale', ['locale' => 'fa'])->assertRedirect('/');
    });

    it('refuses a locale outside the allowlist', function (mixed $locale) {
        $this->from('/')->post('/locale', ['locale' => $locale])->assertInvalid('locale');

        expect(app()->getLocale())->not->toBe($locale);
    })->with([
        'unsupported language' => 'de',
        'traversal' => '../en',
        'empty string' => '',
        'array' => [['en']],
    ]);

    it('requires a locale', function () {
        $this->from('/')->post('/locale', [])->assertInvalid('locale');
    });
});

describe('mini app shell', function () {
    it('embeds the localization payload so the SPA needs no extra round trip', function () {
        $response = $this->withoutVite()->withCookie('locale', 'fa')->get('/miniapp')
            ->assertOk()
            ->assertSee('lang="fa"', false)
            ->assertSee('dir="rtl"', false)
            ->assertSee('id="localization"', false);

        /*
        | Blade's @json hex-escapes < and > so a translation can never close the
        | script tag, while leaving the JSON itself parseable by the SPA.
        */
        $embedded = Str::between($response->content(), '<script id="localization" type="application/json">', '</script>');

        expect(json_decode(trim($embedded), true))
            ->toBeArray()
            ->toHaveKeys(['locale', 'direction', 'locales', 'translations'])
            ->and(json_decode(trim($embedded), true)['direction'])->toBe('rtl');
    });

    it('serves the shell for any client-side route beneath /miniapp', function () {
        $this->withoutVite()->get('/miniapp/challenges/42')->assertOk();
    });
});
