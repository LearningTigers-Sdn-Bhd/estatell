<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\CityLedger\CompanyBulkAddController;
use App\Http\Controllers\CityLedger\CompanyController;
use App\Http\Controllers\CityLedger\CompanyStatusController;
use App\Http\Requests\CityLedger\CompanyRequest;
use Illuminate\Support\Facades\Route;

Route::prefix('finance')->name('finance.')->group(function () {
    Route::prefix('city-ledger/companies')->name('city-ledger.companies.')->group(function () {
        Route::get('/', [CompanyController::class, 'index'])->middleware('can:'.PermissionEnum::CompanyView->value)->name('index');

        Route::middleware('can:'.PermissionEnum::CompanyManage->value)->group(function () {
            Route::post('/', [CompanyController::class, 'store'])->name('store');
            Route::get('bulk', [CompanyBulkAddController::class, 'create'])->name('bulk.create');
            Route::post('bulk', [CompanyBulkAddController::class, 'store'])->name('bulk.store');
            Route::post('import', [CompanyBulkAddController::class, 'import'])->name('import');
            Route::get('import-template.{format}', [CompanyBulkAddController::class, 'template'])->name('import-template');
        });

        Route::scopeBindings()->prefix('{company}')->group(function () {
            Route::middleware('can:'.PermissionEnum::CompanyView->value)->group(function () {
                Route::get('/', [CompanyController::class, 'show'])->name('show');
                Route::get('credit', [CompanyController::class, 'credit'])->name('credit');
                Route::get('rates', [CompanyController::class, 'rates'])->name('rates');
            });

            Route::middleware('can:'.PermissionEnum::CompanyManage->value)->group(function () {
                Route::put('billing', [CompanyController::class, 'updateBilling'])->name('billing.update');
                Route::put('rates', [CompanyController::class, 'updateRates'])->name('rates.update');
                Route::put('{section}', [CompanyController::class, 'update'])->whereIn('section', CompanyRequest::SECTIONS)->name('update');
                Route::post('suspend', [CompanyStatusController::class, 'suspend'])->name('suspend');
                Route::post('reactivate', [CompanyStatusController::class, 'reactivate'])->name('reactivate');
            });
        });
    });
});
