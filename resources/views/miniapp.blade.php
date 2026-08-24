<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

        <title>{{ config('app.name', 'Challenges') }}</title>

        {{-- Telegram's SDK must load before our bundle so `window.Telegram.WebApp`
             and `initData` are available when the SPA mounts. --}}
        <script src="https://telegram.org/js/telegram-web-app.js"></script>

        @viteReactRefresh
        @vite('resources/js/miniapp/main.tsx')
    </head>
    <body>
        <div id="app"></div>
    </body>
</html>
