<?php

use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\ResendVerificationEmailController;
use App\Http\Controllers\Api\V1\Auth\VerifyEmailController;
use App\Http\Controllers\Api\V1\Meta\CountriesController;
use Illuminate\Support\Facades\Route;

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
    });
});