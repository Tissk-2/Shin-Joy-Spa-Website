<?php

use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ServiceController;
use App\Models\User;
use App\Support\CustomerIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

Route::get('/', [HomeController::class, 'index'])->name('home');

Route::redirect('/services', '/#menu');

Route::get('/services/{slug}', [ServiceController::class, 'show'])
    ->where('slug', '[a-z0-9-]+')
    ->name('services.show');

Route::get('/booking', [BookingController::class, 'create'])
    ->middleware(['auth', 'verified'])
    ->name('booking');

Route::post('/bookings', [BookingController::class, 'store'])
    ->middleware(['auth', 'verified', 'throttle:booking-store'])
    ->name('bookings.store');

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

$verificationLimiter = config('fortify.limiters.verification', '6,1');

Route::middleware('auth')->group(function () use ($verificationLimiter): void {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', "throttle:{$verificationLimiter}"])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware("throttle:{$verificationLimiter}")
        ->name('verification.send');
});
