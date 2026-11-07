<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $localization['locale']) }}" dir="{{ $localization['direction'] }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

        <title>{{ config('app.name', 'Challenges') }}</title>

        {{-- Telegram's SDK must load before our bundle so `window.Telegram.WebApp`
             and `initData` are available when the SPA mounts. --}}
        <script src="https://telegram.org/js/telegram-web-app.js"></script>

        {{-- The SPA has no Inertia props, so the active locale and its catalogue
             are embedded here — same payload shape the Inertia surfaces share. --}}
        <script id="localization" type="application/json">@json($localization)</script>

        {{-- The @font-face rules (Instrument Sans, Vazirmatn) — the same fonts
             the Inertia shell loads, so Farsi reads identically inside Telegram. --}}
        @fonts

        @viteReactRefresh
        @vite('resources/js/miniapp/main.tsx')
    </head>
    <body>
        <div id="app"></div>
    </body>
</html>
