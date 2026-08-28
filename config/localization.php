<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Supported Locales
    |--------------------------------------------------------------------------
    |
    | The allowlist of locales this platform may serve. A locale ends up as a
    | path segment inside the translation loader, so nothing outside this list
    | is ever passed to `App::setLocale()` — see the SetLocale middleware.
    |
    | `native` is what a speaker of that language calls it (rendered in the
    | language switcher); `direction` drives the `dir` attribute and Tailwind's
    | rtl:/ltr: variants.
    |
    */

    'supported' => [
        'en' => ['native' => 'English', 'direction' => 'ltr'],
        'fa' => ['native' => 'فارسی', 'direction' => 'rtl'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Locale Cookie
    |--------------------------------------------------------------------------
    |
    | Remembers the choice of visitors who have no user row yet — which on this
    | platform is most of them, since bot users authenticate by Telegram
    | identity. Encrypted like every other cookie; only the server reads it.
    |
    */

    'cookie' => 'locale',

    /*
    |--------------------------------------------------------------------------
    | Client Translation Groups
    |--------------------------------------------------------------------------
    |
    | Which `lang/{locale}/{group}.php` files travel to the browser. Server-only
    | copy (bot replies, mail, validation) stays out of this list so it never
    | bloats the Mini App bundle on a mobile network.
    |
    */

    'client_groups' => ['common', 'enums', 'miniapp', 'admin'],

];
