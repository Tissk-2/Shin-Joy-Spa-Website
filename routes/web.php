<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::post('/locale', function (Request $request) {
    $validated = $request->validate([
        'locale' => ['required', Rule::in(['id', 'en'])],
    ]);

    $request->session()->put('locale', $validated['locale']);

    return back(status: 303);
})->name('locale.update');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::get('profile', function () {
    return Inertia::render('Profile');
})->middleware(['auth', 'verified'])->name('profile');

require __DIR__.'/settings.php';
