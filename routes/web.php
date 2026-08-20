<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

Route::inertia('/', 'Home')->name('home');

Route::redirect('/services', '/#services');

Route::get('/services/{slug}', fn (string $slug) => Inertia::render('ServiceDetail', [
    'slug' => $slug,
]))
    ->where('slug', '[a-z0-9-]+')
    ->name('services.show');

Route::inertia('/booking', 'Booking')->name('booking');

Route::redirect('/about', '/#about');

Route::post('/locale', function (Request $request) {
    $validated = $request->validate([
        'locale' => ['required', Rule::in(['id', 'en'])],
    ]);

    $request->session()->put('locale', $validated['locale']);

    return back(status: 303);
})->name('locale.update');

Route::inertia('/dashboard', 'Dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::inertia('/profile', 'Profile')
    ->middleware(['auth', 'verified'])
    ->name('profile');

require __DIR__.'/settings.php';
