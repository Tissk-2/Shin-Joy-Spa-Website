<?php

use App\Http\Controllers\Admin\AdminBookingController;
use App\Http\Controllers\Admin\AdminBookingSettingsController;
use App\Http\Controllers\Admin\AdminCatalogController;
use App\Http\Controllers\Admin\AdminCatalogPackageController;
use App\Http\Controllers\Admin\AdminContentController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminLoginController;
use App\Http\Controllers\Admin\AdminRoomBlockController;
use App\Http\Controllers\Admin\AdminRoomController;
use App\Http\Controllers\Admin\AdminStaffController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/login', AdminLoginController::class)->name('login');

    Route::middleware(['auth', 'verified', 'admin'])->group(function (): void {
        Route::get('/', AdminDashboardController::class)->name('index');
        Route::get('/staff', [AdminStaffController::class, 'index'])->name('staff.index');
        Route::patch('/staff/{user}/booking-permission', [AdminStaffController::class, 'update'])
            ->whereNumber('user')->middleware('throttle:room-write')->name('staff.booking-permission.update');
        Route::get('/settings/booking', [AdminBookingSettingsController::class, 'edit'])->name('settings.booking.edit');
        Route::patch('/settings/booking', [AdminBookingSettingsController::class, 'update'])
            ->middleware('throttle:room-write')->name('settings.booking.update');
        Route::get('/content', [AdminContentController::class, 'index'])->name('content.index');
        Route::post('/content', [AdminContentController::class, 'store'])->name('content.store');
        Route::post('/content/reset', [AdminContentController::class, 'reset'])
            ->middleware('throttle:catalog-write')
            ->name('content.reset');
        Route::get('/catalog', [AdminCatalogController::class, 'index'])->name('catalog.index');
        Route::get('/catalog/packages/{service:slug}/edit', [AdminCatalogPackageController::class, 'edit'])
            ->name('catalog.packages.edit');
        Route::middleware('throttle:catalog-write')->group(function (): void {
            Route::patch('/catalog/packages/{service:slug}', [AdminCatalogPackageController::class, 'update'])
                ->name('catalog.packages.update');
            Route::post('/catalog/packages/{service:slug}/pricing', [AdminCatalogPackageController::class, 'attestPricing'])
                ->name('catalog.packages.pricing.attest');
            Route::post('/catalog/packages/{service:slug}/publish', [AdminCatalogPackageController::class, 'publish'])
                ->name('catalog.packages.publish');
            Route::post('/catalog/packages/{service:slug}/archive', [AdminCatalogPackageController::class, 'archive'])
                ->name('catalog.packages.archive');
            Route::post('/catalog/packages/{service:slug}/restore', [AdminCatalogPackageController::class, 'restore'])
                ->name('catalog.packages.restore');
            Route::delete('/catalog/packages/{service:slug}', [AdminCatalogPackageController::class, 'destroy'])
                ->name('catalog.packages.destroy');
        });
        Route::get('/bookings', [AdminBookingController::class, 'index'])->name('bookings.index');
        Route::get('/bookings/{booking:reference}', [AdminBookingController::class, 'show'])->name('bookings.show');
        Route::patch('/bookings/{booking:reference}/status', [AdminBookingController::class, 'updateStatus'])
            ->middleware('throttle:admin-booking-status')
            ->name('bookings.status.update');
        Route::get('/room-blocks', [AdminRoomBlockController::class, 'index'])->name('room-blocks.index');
        Route::get('/room-blocks/create', [AdminRoomBlockController::class, 'create'])->name('room-blocks.create');
        Route::get('/room-blocks/{block}/edit', [AdminRoomBlockController::class, 'edit'])->whereNumber('block')->name('room-blocks.edit');
        Route::middleware('throttle:room-write')->group(function (): void {
            Route::post('/room-blocks', [AdminRoomBlockController::class, 'store'])->name('room-blocks.store');
            Route::patch('/room-blocks/{block}', [AdminRoomBlockController::class, 'update'])->whereNumber('block')->name('room-blocks.update');
            Route::post('/room-blocks/{block}/terminate', [AdminRoomBlockController::class, 'terminate'])->whereNumber('block')->name('room-blocks.terminate');
        });
        Route::get('/rooms', [AdminRoomController::class, 'index'])->name('rooms.index');
        Route::get('/rooms/create', [AdminRoomController::class, 'create'])->name('rooms.create');
        Route::get('/rooms/{room}/edit', [AdminRoomController::class, 'edit'])->whereNumber('room')->name('rooms.edit');
        Route::middleware('throttle:room-write')->group(function (): void {
            Route::post('/rooms', [AdminRoomController::class, 'store'])->name('rooms.store');
            Route::patch('/rooms/{room}', [AdminRoomController::class, 'update'])->whereNumber('room')->name('rooms.update');
        });
    });
});
