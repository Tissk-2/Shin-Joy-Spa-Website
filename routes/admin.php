<?php

use App\Http\Controllers\Admin\AdminBookingController;
use App\Http\Controllers\Admin\AdminCatalogCategoryController;
use App\Http\Controllers\Admin\AdminCatalogController;
use App\Http\Controllers\Admin\AdminCatalogPackageController;
use App\Http\Controllers\Admin\AdminLoginController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/login', AdminLoginController::class)->name('login');

    Route::middleware(['auth', 'verified', 'admin'])->group(function (): void {
        Route::redirect('/', '/admin/bookings')->name('index');
        Route::get('/catalog', [AdminCatalogController::class, 'index'])->name('catalog.index');
        Route::get('/catalog/packages/create', [AdminCatalogPackageController::class, 'create'])
            ->name('catalog.packages.create');
        Route::get('/catalog/packages/{service:slug}/edit', [AdminCatalogPackageController::class, 'edit'])
            ->name('catalog.packages.edit');
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
            Route::post('/catalog/packages', [AdminCatalogPackageController::class, 'store'])
                ->name('catalog.packages.store');
            Route::patch('/catalog/packages/{service:slug}', [AdminCatalogPackageController::class, 'update'])
                ->name('catalog.packages.update');
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
    });
});
