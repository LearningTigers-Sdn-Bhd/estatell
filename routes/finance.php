<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\CityLedger\AgingController;
use App\Http\Controllers\CityLedger\ArInvoiceController;
use App\Http\Controllers\CityLedger\CompanyBulkAddController;
use App\Http\Controllers\CityLedger\CompanyController;
use App\Http\Controllers\CityLedger\CompanyPaymentController;
use App\Http\Controllers\CityLedger\CompanyPortalLoginController;
use App\Http\Controllers\CityLedger\CompanyStatementController;
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
            Route::post('portal-logins/message', [CompanyPortalLoginController::class, 'messages'])->name('portal-logins.message');
            Route::post('portal-logins/export', [CompanyPortalLoginController::class, 'export'])->name('portal-logins.export');
        });

        Route::scopeBindings()->prefix('{company}')->group(function () {
            Route::middleware('can:'.PermissionEnum::CompanyView->value)->group(function () {
                Route::get('/', [CompanyController::class, 'show'])->name('show');
                Route::get('credit', [CompanyController::class, 'credit'])->name('credit');
                Route::get('rates', [CompanyController::class, 'rates'])->name('rates');
                Route::get('portal', [CompanyController::class, 'portal'])->name('portal');
                Route::get('payments', [CompanyController::class, 'payments'])->name('payments');
                Route::get('statement', [CompanyController::class, 'statement'])->name('statement');
                Route::get('statement.pdf', [CompanyStatementController::class, 'pdf'])->name('statement.pdf');
                Route::get('payments/{payment}/receipt', [CompanyPaymentController::class, 'receipt'])->name('payments.receipt');
            });

            Route::prefix('payments')->name('payments.')->group(function () {
                Route::post('/', [CompanyPaymentController::class, 'store'])->middleware('can:'.PermissionEnum::CityLedgerPayment->value)->name('store');
                Route::post('reference-check', [CompanyPaymentController::class, 'checkReference'])->middleware('can:'.PermissionEnum::CityLedgerPayment->value)->name('reference-check');
                Route::post('{payment}/void', [CompanyPaymentController::class, 'void'])->middleware('can:'.PermissionEnum::CityLedgerPayment->value)->name('void');
                Route::post('{payment}/allocations', [CompanyPaymentController::class, 'allocate'])->middleware('can:'.PermissionEnum::CityLedgerAllocate->value)->name('allocations.store');
                Route::post('{payment}/allocations/{allocation}/reverse', [CompanyPaymentController::class, 'reverse'])->middleware('can:'.PermissionEnum::CityLedgerAllocate->value)->name('allocations.reverse');
            });

            Route::middleware('can:'.PermissionEnum::CompanyManage->value)->group(function () {
                Route::put('billing', [CompanyController::class, 'updateBilling'])->name('billing.update');
                Route::post('portal/message', [CompanyPortalLoginController::class, 'message'])->name('portal.message');
                Route::post('portal/reset', [CompanyPortalLoginController::class, 'reset'])->name('portal.reset');
                Route::post('portal/resend', [CompanyPortalLoginController::class, 'resend'])->name('portal.resend');
                Route::put('{section}', [CompanyController::class, 'update'])->whereIn('section', CompanyRequest::SECTIONS)->name('update');
                Route::post('suspend', [CompanyStatusController::class, 'suspend'])->name('suspend');
                Route::post('reactivate', [CompanyStatusController::class, 'reactivate'])->name('reactivate');
            });
        });
    });

    Route::prefix('city-ledger/invoices')->name('city-ledger.invoices.')->middleware('can:'.PermissionEnum::CompanyView->value)->scopeBindings()->group(function () {
        Route::get('/', [ArInvoiceController::class, 'index'])->name('index');

        Route::prefix('{invoice}')->group(function () {
            Route::get('/', [ArInvoiceController::class, 'show'])->name('show');
            Route::get('pdf', [ArInvoiceController::class, 'pdf'])->name('pdf');
            Route::post('credit-notes', [ArInvoiceController::class, 'storeCreditNote'])->middleware('can:'.PermissionEnum::CityLedgerCreditNote->value)->name('credit-notes.store');
            Route::post('void', [ArInvoiceController::class, 'void'])->name('void');
        });
    });

    Route::prefix('city-ledger/aging')->middleware('can:'.PermissionEnum::CompanyView->value)->group(function () {
        Route::get('/', [AgingController::class, 'index'])->name('city-ledger.aging');
        Route::get('csv', [AgingController::class, 'csv'])->name('city-ledger.aging.csv');
        Route::get('pdf', [AgingController::class, 'pdf'])->name('city-ledger.aging.pdf');
    });
});
