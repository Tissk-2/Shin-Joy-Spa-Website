<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        @if (request()->routeIs('home'))
            {{-- Home.vue draws the hero only after the JavaScript runs; start the download now. --}}
            <link rel="preload" as="image" href="/img/hero.webp" fetchpriority="high">
        @endif

        @routes
        @vite(['resources/js/app.ts'])
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>
