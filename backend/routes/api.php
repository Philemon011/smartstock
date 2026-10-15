<?php

use App\Http\Controllers\Api\V1\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\ResendVerificationEmailController;
use App\Http\Controllers\Api\V1\Auth\ResetPasswordController;
use App\Http\Controllers\Api\V1\Auth\VerifyEmailController;
use App\Http\Controllers\Api\V1\Meta\CountriesController;
use App\Http\Controllers\Api\V1\WarehouseController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\CategoryController;

Route::prefix('v1')->group(function (): void {
    Route::get('meta/countries', CountriesController::class)
        ->middleware('throttle:60,1')
        ->name('meta.countries');

    Route::prefix('auth')->group(function (): void {
        Route::post('register', RegisterController::class)
            ->middleware('throttle:register')
            ->name('auth.register');

        Route::post('email/resend', ResendVerificationEmailController::class)
            ->middleware('throttle:resend-verification')
            ->name('auth.email.resend');

        Route::get('email/verify/{id}/{hash}', VerifyEmailController::class)
            ->middleware('throttle:verify-email')
            ->name('verification.verify');

        Route::post('login', LoginController::class)
            ->middleware('throttle:login')
            ->name('auth.login');

        Route::post('forgot-password', ForgotPasswordController::class)
            ->middleware('throttle:forgot-password')
            ->name('auth.password.forgot');

        Route::post('reset-password', ResetPasswordController::class)
            ->middleware('throttle:reset-password')
            ->name('auth.password.reset');

        Route::post('logout', LogoutController::class)
            ->middleware('auth:sanctum')
            ->name('auth.logout');
    });

    Route::get('me', MeController::class)
        ->middleware('auth:sanctum')
        ->name('me');

        
    Route::middleware(['auth:sanctum', 'tenant', 'tenant.writable'])->group(function (): void {
        Route::apiResource('warehouses', WarehouseController::class);
        Route::post('warehouses/{warehouse}/set-main', [WarehouseController::class, 'setMain'])->name('warehouses.set-main');
        Route::post('warehouses/{warehouse}/activate', [WarehouseController::class, 'activate'])->name('warehouses.activate');
        Route::post('warehouses/{warehouse}/deactivate', [WarehouseController::class, 'deactivate'])->name('warehouses.deactivate');


        
        Route::apiResource('categories', CategoryController::class);
        Route::post('categories/{category}/activate', [CategoryController::class, 'activate'])->name('categories.activate');
        Route::post('categories/{category}/deactivate', [CategoryController::class, 'deactivate'])->name('categories.deactivate');
    });
});