<?php

use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\DestinationController;
use App\Http\Controllers\AuthController;
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
    });
});

Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');

Route::middleware(['auth:frontend', 'abilities:frontend'])->group(function (): void {
    Route::get('me', [AuthController::class, 'me'])->name('me');
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
});
