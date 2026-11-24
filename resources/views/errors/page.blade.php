{{-- The site's error page for every status. Self-contained (no Vite, no session, no
     database), so it still renders when the app itself is failing; both languages, since
     the visitor's choice may not be known here. The framework ships its own 403, 404, 419,
     429, 500 and 503 pages and Laravel prefers an exact code, so each of those has a
     one-line file that includes this one; 4xx and 5xx catch the rest. --}}
@php
    $code = $exception->getStatusCode();
    [$titleId, $bodyId, $titleEn, $bodyEn] = match (true) {
        $code === 403 => ['Halaman ini tidak tersedia untuk akun Anda', 'Masuk dengan akun yang memiliki akses, atau kembali ke beranda.', 'This page is not available to your account', 'Sign in with an account that has access, or go back to the home page.'],
        $code === 404 => ['Halaman tidak ditemukan', 'Alamat ini tidak ada atau sudah dipindahkan.', 'Page not found', 'This address does not exist or has moved.'],
        $code === 419 => ['Sesi Anda berakhir', 'Muat ulang halaman, lalu coba lagi.', 'Your session expired', 'Reload the page and try again.'],
        $code === 429 => ['Terlalu banyak permintaan', 'Tunggu sebentar, lalu coba lagi.', 'Too many requests', 'Wait a moment and try again.'],
        $code === 503 => ['Sedang dalam perbaikan', 'Situs akan kembali sebentar lagi.', 'Down for maintenance', 'The site will be back shortly.'],
        $code >= 500 => ['Terjadi kesalahan di sisi kami', 'Coba lagi sebentar lagi.', 'Something went wrong on our side', 'Please try again shortly.'],
        default => ['Permintaan tidak dapat diproses', 'Periksa alamatnya, atau kembali ke beranda.', 'This request could not be processed', 'Check the address, or go back to the home page.'],
    };
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $titleId }} · Shin Joy Spa</title>
    <style>
        @font-face { font-family: "Marcellus"; font-weight: 400; font-display: swap; src: url("/fonts/marcellus-latin.woff2") format("woff2"); }
        @font-face { font-family: "Mulish"; font-weight: 300 800; font-display: swap; src: url("/fonts/mulish-latin.woff2") format("woff2"); }
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 2rem 1.25rem; background: #faf7f2; color: #241a18; font: 400 1rem/1.6 "Mulish", system-ui, sans-serif; }
        main { width: 100%; max-width: 34rem; display: grid; gap: 1.5rem; }
        .brand { color: #6e242d; font: 400 1.4rem/1 "Marcellus", Georgia, serif; text-decoration: none; }
        .code { margin: 0; color: #6a5e5b; font-size: 0.85rem; font-weight: 700; letter-spacing: 0.08em; }
        h1 { margin: 0.25rem 0 0; font: 400 clamp(1.7rem, 6vw, 2.3rem)/1.2 "Marcellus", Georgia, serif; text-wrap: balance; }
        p { margin: 0; }
        .en { padding-top: 1rem; border-top: 1px solid #ebe4da; color: #6a5e5b; font-size: 0.95rem; }
        .en strong { color: #241a18; }
        nav { display: flex; flex-wrap: wrap; gap: 0.75rem; }
        nav a { display: inline-flex; align-items: center; min-height: 44px; padding: 0 1.1rem; border: 1px solid #ebe4da; border-radius: 999px; color: #241a18; font-weight: 700; text-decoration: none; }
        nav a:first-child { border-color: #8c3b43; background: #8c3b43; color: #fff; }
        nav a:focus-visible { outline: 3px solid #6e242d; outline-offset: 2px; }
    </style>
</head>
<body>
    <main>
        <a class="brand" href="/">Shin Joy</a>
        <div>
            <p class="code">{{ $code }}</p>
            <h1>{{ $titleId }}</h1>
        </div>
        <p>{{ $bodyId }}</p>
        <nav aria-label="Navigasi">
            <a href="/">Beranda</a>
            <a href="/services">Menu perawatan</a>
            <a href="/bookings">Reservasi saya</a>
        </nav>
        <p class="en" lang="en"><strong>{{ $titleEn }}.</strong> {{ $bodyEn }}</p>
    </main>
</body>
</html>
