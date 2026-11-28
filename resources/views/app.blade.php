<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        {{-- app.css asks for these only after it downloads; text then jumps when they land. --}}
        <link rel="preload" href="/fonts/marcellus-latin.woff2" as="font" type="font/woff2" crossorigin>
        <link rel="preload" href="/fonts/mulish-latin.woff2" as="font" type="font/woff2" crossorigin>

        @if (request()->routeIs('home'))
            {{-- Home.vue draws the hero only after the JavaScript runs; start the download now. --}}
            <link rel="preload" as="image" href="/img/hero.webp" imagesrcset="/img/hero-1600.webp 1600w, /img/hero.webp 2000w" imagesizes="(min-aspect-ratio: 39/23) 100vw, 156vh" fetchpriority="high">
        @endif

        {{-- The page's own script too, so it downloads alongside app.js instead of after it. --}}
        @vite(['resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>
