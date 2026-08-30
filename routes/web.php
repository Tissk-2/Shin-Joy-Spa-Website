<?php

use App\Models\User;
use App\Support\CustomerIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
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

if (app()->environment('testing')) {
    Route::get('/__testing/verification-link', function (Request $request) {
        $validated = $request->validate(['email' => ['required', 'email']]);
        $user = User::query()
            ->where('email', CustomerIdentity::email($validated['email']))
            ->firstOrFail();

        return response()->json(['url' => URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
        )]);
    })->middleware('throttle:10,1')->name('testing.verification-link');

    Route::get('/__testing/reset-link', function (Request $request) {
        $validated = $request->validate(['email' => ['required', 'email']]);
        $user = User::query()
            ->where('email', CustomerIdentity::email($validated['email']))
            ->firstOrFail();
        $token = Password::broker()->createToken($user);

        return response()->json([
            'url' => url('/reset-password/'.$token).'?email='.urlencode($user->email),
        ]);
    })->middleware('throttle:10,1')->name('testing.reset-link');
}

require __DIR__.'/settings.php';
