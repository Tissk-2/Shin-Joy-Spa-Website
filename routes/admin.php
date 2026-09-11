<?php

use App\Http\Controllers\Admin\AdminBookingController;
use App\Http\Controllers\Admin\AdminCatalogCategoryController;
use App\Http\Controllers\Admin\AdminCatalogController;
use App\Http\Controllers\Admin\AdminLoginController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/login', AdminLoginController::class)->name('login');

    Route::middleware(['auth', 'verified', 'admin'])->group(function (): void {
        Route::redirect('/', '/admin/bookings')->name('index');
        Route::get('/catalog', [AdminCatalogController::class, 'index'])->name('catalog.index');
        Route::middleware('throttle:catalog-write')->group(function (): void {
            Route::post('/catalog/categories', [AdminCatalogCategoryController::class, 'store'])
                ->name('catalog.categories.store');
            Route::patch('/catalog/categories/{category:slug}', [AdminCatalogCategoryController::class, 'update'])
                ->name('catalog.categories.update');
            Route::post('/catalog/categories/{category:slug}/publish', [AdminCatalogCategoryController::class, 'publish'])
                ->name('catalog.categories.publish');
            Route::post('/catalog/categories/{category:slug}/archive', [AdminCatalogCategoryController::class, 'archive'])
                ->name('catalog.categories.archive');
            Route::post('/catalog/categories/{category:slug}/restore', [AdminCatalogCategoryController::class, 'restore'])
                ->name('catalog.categories.restore');
            Route::delete('/catalog/categories/{category:slug}', [AdminCatalogCategoryController::class, 'destroy'])
                ->name('catalog.categories.destroy');
        });
        Route::get('/bookings', [AdminBookingController::class, 'index'])->name('bookings.index');
        Route::get('/bookings/{booking:reference}', [AdminBookingController::class, 'show'])->name('bookings.show');
        Route::patch('/bookings/{booking:reference}/status', [AdminBookingController::class, 'updateStatus'])
            ->middleware('throttle:admin-booking-status')
            ->name('bookings.status.update');
    });
});
