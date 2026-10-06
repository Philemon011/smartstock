<?php

use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\ResendVerificationEmailController;
use App\Http\Controllers\Api\V1\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
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
    });
});