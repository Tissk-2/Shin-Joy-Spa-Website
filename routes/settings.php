<?php

use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    // The account page is /profile; the old settings addresses lead there.
    // GET only: Route::redirect would also answer the PATCH and DELETE below.
    Route::get('settings', fn () => redirect('/profile'));

    Route::get('settings/profile', fn () => redirect('/profile'))->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])
        ->middleware('throttle:password-update')
        ->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])
        ->middleware('throttle:profile-destroy')
        ->name('profile.destroy');

    Route::get('settings/password', fn () => redirect('/profile#password'));
    Route::put('settings/password', [PasswordController::class, 'update'])
        ->middleware('throttle:password-update')
        ->name('settings.password.update');

    Route::get('settings/appearance', fn () => redirect('/profile'));
});
