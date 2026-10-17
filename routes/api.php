<?php

use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\CarCategoryController;
use App\Http\Controllers\Admin\CarController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\DestinationController;
use App\Http\Controllers\Admin\PackageController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CarController as PublicCarController;
use App\Http\Controllers\CategoryController as PublicCategoryController;
use App\Http\Controllers\DestinationController as PublicDestinationController;
use App\Http\Controllers\PackageController as PublicPackageController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::post('login', [AdminAuthController::class, 'login'])->middleware('throttle:login')->name('login');

    Route::middleware(['auth:admin', 'abilities:admin', 'admin.active'])->group(function (): void {
        Route::get('me', [AdminAuthController::class, 'me'])->name('me');
        Route::post('logout', [AdminAuthController::class, 'logout'])->name('logout');
        Route::patch('admin-users/{admin_user}/password', [AdminUserController::class, 'updatePassword'])
            ->middleware('can:manage-admin-users')->name('admin-users.update-password');
        Route::apiResource('admin-users', AdminUserController::class)->parameters(['admin-users' => 'admin_user'])->middleware('can:manage-admin-users');
        Route::apiResource('categories', CategoryController::class)->middleware('can:manage-categories');
        Route::apiResource('destinations', DestinationController::class)->middleware('can:manage-destinations');
        Route::apiResource('car-categories', CarCategoryController::class)->parameters(['car-categories' => 'car_category'])->middleware('can:manage-car-categories');
        Route::apiResource('cars', CarController::class)->middleware('can:manage-cars');
        Route::middleware('can:manage-packages')->group(function (): void {
            Route::post('packages/basic-details', [PackageController::class, 'saveBasicDetails'])->name('packages.basic-details.store');
            Route::patch('packages/{package}/basic-details', [PackageController::class, 'saveBasicDetails'])->name('packages.basic-details.update');
            Route::patch('packages/{package}/categories-destinations', [PackageController::class, 'saveRelations'])->name('packages.categories-destinations.update');
            Route::patch('packages/{package}/itineraries', [PackageController::class, 'saveItineraries'])->name('packages.itineraries.update');
            Route::patch('packages/{package}/details', [PackageController::class, 'saveDetails'])->name('packages.details.update');
            Route::patch('packages/{package}/photos', [PackageController::class, 'savePhotos'])->name('packages.photos.update');
            Route::apiResource('packages', PackageController::class)->only(['index', 'show', 'destroy']);
        });
    });
});

Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');

Route::get('packages/special', [PublicPackageController::class, 'getSpecialPackages'])->name('packages.special');
Route::apiResource('packages', PublicPackageController::class)->only(['index', 'show']);
Route::get('destinations/top', [PublicDestinationController::class, 'getTopDestination'])->name('destinations.top');
Route::apiResource('destinations', PublicDestinationController::class)->only(['index', 'show']);
Route::get('categories/popular', [PublicCategoryController::class, 'getPopularCategories'])->name('categories.popular');
Route::apiResource('categories', PublicCategoryController::class)->only(['index', 'show']);
Route::get('cars/categories', [PublicCarController::class, 'getCarCategories'])->name('cars.categories');
Route::apiResource('cars', PublicCarController::class)->only(['index', 'show']);

Route::middleware(['auth:frontend', 'abilities:frontend'])->group(function (): void {
    Route::get('me', [AuthController::class, 'me'])->name('me');
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
});
