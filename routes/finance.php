<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\CityLedger\CompanyController;
use App\Http\Controllers\CityLedger\CompanyStatusController;
use App\Http\Requests\CityLedger\CompanyRequest;
use Illuminate\Support\Facades\Route;

Route::prefix('finance')->name('finance.')->group(function () {
    Route::prefix('city-ledger/companies')->name('city-ledger.companies.')->group(function () {
        Route::get('/', [CompanyController::class, 'index'])->middleware('can:'.PermissionEnum::CompanyView->value)->name('index');

        Route::post('/', [CompanyController::class, 'store'])->middleware('can:'.PermissionEnum::CompanyManage->value)->name('store');

        Route::scopeBindings()->prefix('{company}')->group(function () {
            Route::middleware('can:'.PermissionEnum::CompanyView->value)->group(function () {
                Route::get('/', [CompanyController::class, 'show'])->name('show');
                Route::get('credit', [CompanyController::class, 'credit'])->name('credit');
            });

            Route::middleware('can:'.PermissionEnum::CompanyManage->value)->group(function () {
                Route::put('billing', [CompanyController::class, 'updateBilling'])->name('billing.update');
                Route::put('{section}', [CompanyController::class, 'update'])->whereIn('section', CompanyRequest::SECTIONS)->name('update');
                Route::post('suspend', [CompanyStatusController::class, 'suspend'])->name('suspend');
                Route::post('reactivate', [CompanyStatusController::class, 'reactivate'])->name('reactivate');
            });
        });
    });
});
