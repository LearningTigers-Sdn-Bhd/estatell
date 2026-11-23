<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\Events\EventController;
use App\Http\Controllers\Events\EventFolioController;
use App\Http\Controllers\Events\EventPaymentController;
use Illuminate\Support\Facades\Route;

Route::prefix('events')->name('events.')->middleware('facilities')->scopeBindings()->group(function () {
    Route::middleware('can:'.PermissionEnum::EventsView->value)->group(function () {
        Route::get('/', [EventController::class, 'index'])->name('index');
        Route::get('new', [EventController::class, 'create'])->middleware('can:'.PermissionEnum::EventsManage->value)->name('create');
        Route::post('/', [EventController::class, 'store'])->middleware('can:'.PermissionEnum::EventsManage->value)->name('store');
        Route::post('quote', [EventController::class, 'quote'])->middleware(['can:'.PermissionEnum::EventsManage->value, 'can:'.PermissionEnum::FacilitiesBook->value])->name('quote');

        Route::prefix('{event}')->group(function () {
            Route::get('/', [EventController::class, 'show'])->name('show');
            Route::get('bookings', [EventController::class, 'bookings'])->name('bookings');
            Route::get('booking-summary', [EventController::class, 'bookingSummary'])->name('booking-summary');

            Route::middleware('can:'.PermissionEnum::EventsManage->value)->group(function () {
                Route::put('/', [EventController::class, 'update'])->name('update');
                Route::post('confirm', [EventController::class, 'confirm'])->name('confirm');
                Route::post('cancel', [EventController::class, 'cancel'])->name('cancel');
            });

            Route::post('payments/quote', [EventPaymentController::class, 'quote'])->middleware('can:'.PermissionEnum::PaymentRecord->value)->name('payments.quote');

            Route::prefix('folios/{folio}')->name('folios.')->group(function () {
                Route::post('charges', [EventFolioController::class, 'storeCharge'])->middleware('can:'.PermissionEnum::FolioPostCharge->value)->name('charges.store');
                Route::post('lines/{line}/reversal', [EventFolioController::class, 'reverse'])->middleware('can:'.PermissionEnum::FolioReverse->value)->name('lines.reverse');
                Route::post('discounts/quote', [EventFolioController::class, 'quoteDiscount'])->middleware('can:'.PermissionEnum::FolioDiscount->value)->name('discounts.quote');
                Route::post('discounts', [EventFolioController::class, 'storeDiscount'])->middleware('can:'.PermissionEnum::FolioDiscount->value)->name('discounts.store');
                Route::delete('lines/{line}/discount', [EventFolioController::class, 'destroyDiscount'])->middleware('can:'.PermissionEnum::FolioDiscount->value)->name('discounts.destroy');
                Route::post('city-ledger', [EventFolioController::class, 'settleCityLedger'])->middleware('can:'.PermissionEnum::CityLedgerInvoice->value)->name('city-ledger');
                Route::post('close', [EventFolioController::class, 'close'])->middleware('can:'.PermissionEnum::FolioClose->value)->name('close');
                Route::post('reopen', [EventFolioController::class, 'reopen'])->name('reopen');
                Route::post('void-invoice-and-reopen', [EventFolioController::class, 'voidInvoiceAndReopen'])->middleware(['can:'.PermissionEnum::CityLedgerInvoice->value, 'can:'.PermissionEnum::FolioReopen->value])->name('void-invoice-and-reopen');
                Route::post('payments', [EventPaymentController::class, 'store'])->middleware('can:'.PermissionEnum::PaymentRecord->value)->name('payments.store');
                Route::post('payments/{payment}/refunds', [EventPaymentController::class, 'refund'])->middleware('can:'.PermissionEnum::PaymentRefund->value)->name('payments.refunds.store');
                Route::post('payments/{payment}/void', [EventPaymentController::class, 'void'])->middleware('can:'.PermissionEnum::PaymentRefund->value)->name('payments.void');
                Route::post('refund-credit', [EventPaymentController::class, 'refundCredit'])->middleware('can:'.PermissionEnum::PaymentRefund->value)->name('refund-credit.store');
                Route::get('payments/{payment}/receipt', [EventPaymentController::class, 'receipt'])->middleware('can:'.PermissionEnum::PaymentRecord->value)->name('payments.receipt');
                Route::get('voucher', [EventFolioController::class, 'voucher'])->middleware('can:'.PermissionEnum::FolioPrintVoucher->value)->name('voucher');
                Route::get('invoice', [EventFolioController::class, 'invoice'])->middleware('can:'.PermissionEnum::FolioPrintVoucher->value)->name('invoice');
            });
        });
    });
});
