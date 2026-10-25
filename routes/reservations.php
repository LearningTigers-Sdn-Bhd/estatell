<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\Reservations\BookingSummaryController;
use App\Http\Controllers\Reservations\ReservationBoatTripController;
use App\Http\Controllers\Reservations\ReservationBookerController;
use App\Http\Controllers\Reservations\ReservationBookingController;
use App\Http\Controllers\Reservations\ReservationCityLedgerController;
use App\Http\Controllers\Reservations\ReservationCompanyController;
use App\Http\Controllers\Reservations\ReservationController;
use App\Http\Controllers\Reservations\ReservationFolioController;
use App\Http\Controllers\Reservations\ReservationFolioLineController;
use App\Http\Controllers\Reservations\ReservationFolioManualController;
use App\Http\Controllers\Reservations\ReservationFolioMoveController;
use App\Http\Controllers\Reservations\ReservationGroupCheckOutController;
use App\Http\Controllers\Reservations\ReservationGroupController;
use App\Http\Controllers\Reservations\ReservationNightPriceController;
use App\Http\Controllers\Reservations\ReservationOldFolioController;
use App\Http\Controllers\Reservations\ReservationPaymentController;
use App\Http\Controllers\Reservations\ReservationRoutingController;
use App\Http\Controllers\Reservations\ReservationUnitAddOnController;
use App\Http\Controllers\Reservations\ReservationUnitController;
use App\Http\Controllers\Reservations\ReservationUnitStayController;
use Illuminate\Support\Facades\Route;

Route::prefix('reservations')->name('reservations.')->scopeBindings()->group(function () {
    Route::middleware('can:'.PermissionEnum::ReservationsManage->value)->group(function () {
        Route::post('quote', [ReservationBookingController::class, 'quote'])->name('quote');
        Route::get('new', [ReservationBookingController::class, 'new'])->name('new');
        Route::get('walk-in', [ReservationBookingController::class, 'walkIn'])->name('walk-in');
        Route::post('/', [ReservationBookingController::class, 'store'])->name('store');
        Route::middleware('can:'.PermissionEnum::FolioManual->value)->group(function () {
            Route::get('past', [ReservationBookingController::class, 'past'])->name('past');
            Route::get('migrated', [ReservationBookingController::class, 'migrated'])->name('migrated');
            Route::get('old', [ReservationBookingController::class, 'old'])->name('old');
            Route::post('past', [ReservationBookingController::class, 'storePast'])->name('store-past');
        });
        Route::get('free-units', [ReservationBookingController::class, 'freeUnits'])->name('free-units');
        Route::get('day-use-offers', [ReservationBookingController::class, 'dayUseOffers'])->name('day-use-offers');
        Route::get('boat-seats', [ReservationBookingController::class, 'boatSeats'])->middleware('boats')->name('boat-seats');
    });

    Route::middleware('can:'.PermissionEnum::ReservationsView->value)->group(function () {
        Route::get('/', [ReservationController::class, 'index'])->name('index');

        Route::prefix('{reservation}')->group(function () {
            Route::get('/', [ReservationController::class, 'show'])->name('show');
            Route::get('guests', [ReservationController::class, 'guests'])->name('guests');
            Route::get('add-ons', [ReservationController::class, 'addOns'])->name('add-ons');
            Route::get('folio', [ReservationController::class, 'folio'])->name('folio');
            Route::get('activity', [ReservationController::class, 'activity'])->name('activity');
            Route::get('booking-summary', BookingSummaryController::class)->name('booking-summary');

            Route::middleware('can:'.PermissionEnum::FolioManual->value)->prefix('old-folio')->name('old-folio')->group(function () {
                Route::get('/', [ReservationOldFolioController::class, 'show']);
                Route::post('/', [ReservationOldFolioController::class, 'store'])->name('.store');
                Route::post('folios', [ReservationOldFolioController::class, 'storeFolio'])->name('.folios.store');
            });

            Route::prefix('folios/{folio}')->name('folios.')->group(function () {
                Route::post('charges', [ReservationFolioController::class, 'storeCharge'])->middleware('can:'.PermissionEnum::FolioPostCharge->value)->name('charges.store');
                Route::post('lines/{line}/reversal', [ReservationFolioController::class, 'reverse'])->middleware('can:'.PermissionEnum::FolioReverse->value)->name('lines.reverse');
                Route::post('discounts/quote', [ReservationFolioController::class, 'quoteDiscount'])->middleware('can:'.PermissionEnum::FolioDiscount->value)->name('discounts.quote');
                Route::post('discounts', [ReservationFolioController::class, 'storeDiscount'])->middleware('can:'.PermissionEnum::FolioDiscount->value)->name('discounts.store');
                Route::delete('lines/{line}/discount', [ReservationFolioController::class, 'destroyDiscount'])->middleware('can:'.PermissionEnum::FolioDiscount->value)->name('discounts.destroy');
                Route::middleware('can:'.PermissionEnum::FolioManual->value)->prefix('manual')->name('manual')->group(function () {
                    Route::get('/', [ReservationFolioManualController::class, 'show']);
                    Route::post('start', [ReservationFolioManualController::class, 'start'])->name('.start');
                    Route::post('preview', [ReservationFolioManualController::class, 'preview'])->name('.preview');
                    Route::post('folios', [ReservationFolioManualController::class, 'storeFolio'])->name('.folios.store');
                    Route::post('/', [ReservationFolioManualController::class, 'store'])->name('.store');
                });
                Route::middleware('can:'.PermissionEnum::FolioManual->value)->prefix('lines/{line}')->name('lines.')->group(function () {
                    Route::post('edit', [ReservationFolioLineController::class, 'edit'])->name('edit');
                    Route::post('edit/preview', [ReservationFolioLineController::class, 'previewEdit'])->name('edit.preview');
                    Route::post('split', [ReservationFolioLineController::class, 'split'])->name('split');
                    Route::post('tax/remove', [ReservationFolioLineController::class, 'removeTax'])->name('tax.remove');
                    Route::post('tax', [ReservationFolioLineController::class, 'addTax'])->name('tax.add');
                    Route::post('tax/recalculate', [ReservationFolioLineController::class, 'recalculateTax'])->name('tax.recalculate');
                    Route::post('opening-balance', [ReservationFolioLineController::class, 'changeOpeningBalance'])->name('opening-balance');
                });
                Route::post('city-ledger', [ReservationCityLedgerController::class, 'settle'])->middleware('can:'.PermissionEnum::CityLedgerInvoice->value)->name('city-ledger');
                Route::post('close', [ReservationFolioController::class, 'close'])->middleware('can:'.PermissionEnum::FolioClose->value)->name('close');
                Route::post('reopen', [ReservationFolioController::class, 'reopen'])->name('reopen');
                Route::post('void-invoice-and-reopen', [ReservationFolioController::class, 'voidInvoiceAndReopen'])->middleware(['can:'.PermissionEnum::CityLedgerInvoice->value, 'can:'.PermissionEnum::FolioReopen->value])->name('void-invoice-and-reopen');
                Route::post('payments', [ReservationPaymentController::class, 'storeForFolio'])->middleware('can:'.PermissionEnum::PaymentRecord->value)->name('payments.store');
                Route::post('payments/{payment}/refunds', [ReservationPaymentController::class, 'refund'])->middleware('can:'.PermissionEnum::PaymentRefund->value)->name('payments.refunds.store');
                Route::post('payments/{payment}/void', [ReservationPaymentController::class, 'void'])->middleware('can:'.PermissionEnum::PaymentRefund->value)->name('payments.void');
                Route::post('refund-credit', [ReservationPaymentController::class, 'refundCredit'])->middleware('can:'.PermissionEnum::PaymentRefund->value)->name('refund-credit.store');
                Route::get('voucher', [ReservationFolioController::class, 'voucher'])->middleware('can:'.PermissionEnum::FolioPrintVoucher->value)->name('voucher');
                Route::get('invoice', [ReservationFolioController::class, 'invoice'])->middleware('can:'.PermissionEnum::FolioPrintVoucher->value)->name('invoice');
                Route::get('payments/{payment}/receipt', [ReservationPaymentController::class, 'receipt'])->middleware('can:'.PermissionEnum::PaymentRecord->value)->name('payments.receipt');
            });

            Route::middleware('can:'.PermissionEnum::FolioTransfer->value)->group(function () {
                Route::post('folios/third-party', [ReservationRoutingController::class, 'storeThirdPartyFolio'])->name('folios.third-party.store');
                Route::post('units/{unit}/bill-to-guest', [ReservationRoutingController::class, 'billToGuest'])->name('units.bill-to-guest');
                Route::post('routing-rules', [ReservationRoutingController::class, 'storeRule'])->name('routing-rules.store');
                Route::delete('routing-rules/{routingRule}', [ReservationRoutingController::class, 'destroyRule'])->name('routing-rules.destroy');
                Route::get('folio/move', [ReservationFolioMoveController::class, 'show'])->name('folio.move');
                Route::post('folio/move/preview', [ReservationFolioMoveController::class, 'preview'])->name('folio.move.preview');
                Route::post('folio/move', [ReservationFolioMoveController::class, 'store'])->name('folio.move.store');
            });

            Route::middleware('can:'.PermissionEnum::PaymentRecord->value)->group(function () {
                Route::post('payments/quote', [ReservationPaymentController::class, 'quote'])->name('payments.quote');
                Route::post('units/{unit}/payments', [ReservationPaymentController::class, 'storeForUnit'])->name('units.payments.store');
            });

            Route::get('check-out/receipts', [ReservationGroupCheckOutController::class, 'receipts'])->middleware('can:'.PermissionEnum::PaymentRecord->value)->name('check-out.receipts');

            Route::middleware('can:'.PermissionEnum::FrontDeskCheckOut->value)->group(function () {
                Route::get('check-out', [ReservationGroupCheckOutController::class, 'show'])->name('check-out');
                Route::post('check-out/preview', [ReservationGroupCheckOutController::class, 'preview'])->name('check-out.preview');
                Route::post('check-out', [ReservationGroupCheckOutController::class, 'store'])->name('check-out.store');
            });

            Route::middleware('can:'.PermissionEnum::FrontDeskCheckIn->value)->group(function () {
                Route::post('check-in-all/preview', [ReservationUnitStayController::class, 'checkInAllPreview'])->name('check-in-all-preview');
                Route::post('check-in-all', [ReservationUnitStayController::class, 'checkInAll'])->name('check-in-all');
            });

            Route::prefix('units/{unit}')->name('units.')->group(function () {
                Route::middleware('can:'.PermissionEnum::FrontDeskUndoCheckIn->value)->group(function () {
                    Route::post('undo-check-in/preview', [ReservationUnitStayController::class, 'undoCheckInPreview'])->name('undo-check-in-preview');
                    Route::post('undo-check-in', [ReservationUnitStayController::class, 'undoCheckIn'])->name('undo-check-in');
                });

                Route::middleware('can:'.PermissionEnum::FrontDeskUndoCheckOut->value)->group(function () {
                    Route::post('undo-check-out/preview', [ReservationUnitStayController::class, 'undoCheckOutPreview'])->name('undo-check-out-preview');
                    Route::post('undo-check-out', [ReservationUnitStayController::class, 'undoCheckOut'])->name('undo-check-out');
                });

                Route::middleware('can:'.PermissionEnum::FrontDeskCheckIn->value)->group(function () {
                    Route::post('check-in/preview', [ReservationUnitStayController::class, 'checkInPreview'])->name('check-in-preview');
                    Route::post('check-in', [ReservationUnitStayController::class, 'checkIn'])->name('check-in');
                });

                Route::middleware('can:'.PermissionEnum::FrontDeskCheckOut->value)->group(function () {
                    Route::post('check-out/preview', [ReservationUnitStayController::class, 'checkOutPreview'])->name('check-out-preview');
                    Route::post('check-out', [ReservationUnitStayController::class, 'checkOut'])->name('check-out');
                    Route::post('late-check-out/preview', [ReservationUnitStayController::class, 'lateCheckOutPreview'])->name('late-check-out-preview');
                    Route::post('late-check-out', [ReservationUnitStayController::class, 'lateCheckOut'])->name('late-check-out');
                });

                Route::middleware('can:'.PermissionEnum::ReservationsChangePrice->value)->group(function () {
                    Route::post('night-price/preview', [ReservationNightPriceController::class, 'preview'])->name('night-price.preview');
                    Route::post('night-price', [ReservationNightPriceController::class, 'store'])->name('night-price');
                });
            });

            Route::get('units/{unit}/voucher', [ReservationFolioController::class, 'unitVoucher'])->middleware('can:'.PermissionEnum::FolioPrintVoucher->value)->name('units.voucher');
        });
    });

    Route::middleware('can:'.PermissionEnum::ReservationsManage->value)->prefix('{reservation}')->group(function () {
        Route::put('group', [ReservationGroupController::class, 'update'])->name('group.update');
        Route::put('booker', [ReservationBookerController::class, 'update'])->middleware('can:'.PermissionEnum::GuestsManage->value)->name('booker.update');
        Route::put('company', [ReservationCompanyController::class, 'update'])->middleware('can:'.PermissionEnum::CompanyView->value)->name('company.update');
        Route::middleware('boats')->put('boat-trips', [ReservationBoatTripController::class, 'setMany'])->name('boat-trips.set-many');
        Route::post('cancel/preview', [ReservationUnitStayController::class, 'cancelAllPreview'])->name('cancel-preview');
        Route::post('cancel', [ReservationUnitStayController::class, 'cancelAll'])->name('cancel');

        Route::prefix('units')->name('units.')->group(function () {
            Route::post('/', [ReservationUnitController::class, 'store'])->name('store');

            Route::prefix('{unit}')->group(function () {
                Route::post('guests', [ReservationUnitController::class, 'storeGuest'])->name('guests.store');
                Route::put('guests/{guestId}', [ReservationUnitController::class, 'updateGuest'])->whereNumber('guestId')->middleware('can:'.PermissionEnum::GuestsManage->value)->name('guests.update');
                Route::post('guests/{guestId}/replace', [ReservationUnitController::class, 'replaceGuest'])->whereNumber('guestId')->name('guests.replace');
                Route::post('guests/{guestId}/move', [ReservationUnitController::class, 'moveGuest'])->whereNumber('guestId')->name('guests.move');
                Route::delete('guests/{guestId}', [ReservationUnitController::class, 'destroyGuest'])->whereNumber('guestId')->name('guests.destroy');
                Route::middleware('boats')->group(function () {
                    Route::put('boat-trips/{direction}', [ReservationBoatTripController::class, 'set'])->name('boat-trips.set');
                    Route::delete('boat-trips/{direction}', [ReservationBoatTripController::class, 'clear'])->name('boat-trips.clear');
                });
                Route::post('add-ons', [ReservationUnitAddOnController::class, 'store'])->name('add-ons.store');
                Route::delete('add-ons/{addOn}', [ReservationUnitAddOnController::class, 'destroy'])->name('add-ons.destroy');

                Route::post('cancel/preview', [ReservationUnitStayController::class, 'cancelPreview'])->name('cancel-preview');
                Route::post('cancel', [ReservationUnitStayController::class, 'cancel'])->name('cancel');
                Route::post('no-show/preview', [ReservationUnitStayController::class, 'noShowPreview'])->name('no-show-preview');
                Route::post('no-show', [ReservationUnitStayController::class, 'noShow'])->name('no-show');
                Route::post('reinstate/preview', [ReservationUnitStayController::class, 'reinstatePreview'])->name('reinstate-preview');
                Route::post('reinstate', [ReservationUnitStayController::class, 'reinstate'])->name('reinstate');
                Route::post('dates/preview', [ReservationUnitStayController::class, 'datesPreview'])->name('dates-preview');
                Route::put('dates', [ReservationUnitStayController::class, 'changeDates'])->name('dates');
                Route::post('early-check-out', [ReservationUnitStayController::class, 'earlyCheckOut'])->middleware('can:'.PermissionEnum::FrontDeskCheckOut->value)->name('early-check-out');
                Route::post('move/preview', [ReservationUnitStayController::class, 'movePreview'])->name('move-preview');
                Route::post('move', [ReservationUnitStayController::class, 'move'])->name('move');
                Route::post('move-stay/preview', [ReservationUnitStayController::class, 'moveStayPreview'])->name('move-stay-preview');
                Route::post('move-stay', [ReservationUnitStayController::class, 'moveStay'])->name('move-stay');
            });
        });
    });
});
