<?php

use App\Http\Controllers\Api\V1\AlertController;
use App\Http\Controllers\Api\V1\ArticleController;
use App\Http\Controllers\Api\V1\ArticleImageController;
use App\Http\Controllers\Api\V1\ArticleStockController;
use App\Http\Controllers\Api\V1\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\ResendVerificationEmailController;
use App\Http\Controllers\Api\V1\Auth\ResetPasswordController;
use App\Http\Controllers\Api\V1\Auth\VerifyEmailController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\Meta\CountriesController;
use App\Http\Controllers\Api\V1\StockEntryController;
use App\Http\Controllers\Api\V1\StockEntryDocumentController;
use App\Http\Controllers\Api\V1\StockExitController;
use App\Http\Controllers\Api\V1\StockMetaController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\UnitController;
use App\Http\Controllers\Api\V1\WarehouseController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\CustomerController;

Route::prefix('v1')->group(function (): void {
    Route::get('meta/countries', CountriesController::class)
        ->middleware('throttle:60,1')
        ->name('meta.countries');

    Route::get('files/articles/{article}/image', [ArticleImageController::class, 'show'])
        ->middleware(['signed', 'throttle:240,1'])
        ->name('articles.image');

    Route::get('meta/stock', StockMetaController::class)
        ->middleware('throttle:60,1')
        ->name('meta.stock');

    Route::get('files/stock-entries/{entry}/document', [StockEntryDocumentController::class, 'show'])
        ->middleware(['signed', 'throttle:240,1'])
        ->name('stock-entries.document');

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

        Route::apiResource('suppliers', SupplierController::class);
        Route::post('suppliers/{supplier}/activate', [SupplierController::class, 'activate'])->name('suppliers.activate');
        Route::post('suppliers/{supplier}/deactivate', [SupplierController::class, 'deactivate'])->name('suppliers.deactivate');

        Route::apiResource('units', UnitController::class);
        Route::post('units/{unit}/activate', [UnitController::class, 'activate'])->name('units.activate');
        Route::post('units/{unit}/deactivate', [UnitController::class, 'deactivate'])->name('units.deactivate');

        Route::apiResource('articles', ArticleController::class);
        Route::post('articles/{article}/activate', [ArticleController::class, 'activate'])->name('articles.activate');
        Route::post('articles/{article}/deactivate', [ArticleController::class, 'deactivate'])->name('articles.deactivate');
        Route::get('articles/{article}/price-history', [ArticleController::class, 'priceHistory'])->name('articles.price-history');
        Route::post('articles/{article}/image', [ArticleImageController::class, 'store'])->name('articles.image.store');
        Route::delete('articles/{article}/image', [ArticleImageController::class, 'destroy'])->name('articles.image.destroy');

        Route::get('articles/{article}/stock', [ArticleStockController::class, 'show'])->name('articles.stock');

        Route::apiResource('stock-entries', StockEntryController::class)->parameters(['stock-entries' => 'stock_entry']);
        Route::post('stock-entries/{stock_entry}/validate', [StockEntryController::class, 'validateEntry'])->name('stock-entries.validate');
        Route::post('stock-entries/{stock_entry}/cancel', [StockEntryController::class, 'cancel'])->name('stock-entries.cancel');
        Route::post('stock-entries/{stock_entry}/document', [StockEntryDocumentController::class, 'store'])->name('stock-entries.document.store');
        Route::delete('stock-entries/{stock_entry}/document', [StockEntryDocumentController::class, 'destroy'])->name('stock-entries.document.destroy');

        Route::get('dashboard', DashboardController::class)->name('dashboard');
        Route::get('alerts/summary', [AlertController::class, 'summary'])->name('alerts.summary');
        Route::get('alerts', [AlertController::class, 'index'])->name('alerts.index');

        Route::apiResource('stock-exits', StockExitController::class)
            ->parameters(['stock-exits' => 'stock_exit'])
            ->names('stock-exits');
        Route::post('stock-exits/{stock_exit}/validate', [StockExitController::class, 'validateExit'])->name('stock-exits.validate');
        Route::post('stock-exits/{stock_exit}/cancel', [StockExitController::class, 'cancel'])->name('stock-exits.cancel');


                Route::apiResource('customers', CustomerController::class);
        Route::post('customers/{customer}/activate', [CustomerController::class, 'activate'])->name('customers.activate');
        Route::post('customers/{customer}/deactivate', [CustomerController::class, 'deactivate'])->name('customers.deactivate');
    });
});
