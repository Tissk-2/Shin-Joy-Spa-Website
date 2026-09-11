<?php

use App\Http\Controllers\Admin\AdminBookingController;
use App\Http\Controllers\Admin\AdminCatalogController;
use App\Http\Controllers\Admin\AdminLoginController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/login', AdminLoginController::class)->name('login');

    Route::middleware(['auth', 'verified', 'admin'])->group(function (): void {
        Route::redirect('/', '/admin/bookings')->name('index');
        Route::get('/catalog', [AdminCatalogController::class, 'index'])->name('catalog.index');
        Route::get('/bookings', [AdminBookingController::class, 'index'])->name('bookings.index');
        Route::get('/bookings/{booking:reference}', [AdminBookingController::class, 'show'])->name('bookings.show');
        Route::patch('/bookings/{booking:reference}/status', [AdminBookingController::class, 'updateStatus'])
            ->middleware('throttle:admin-booking-status')
            ->name('bookings.status.update');
    });
});
