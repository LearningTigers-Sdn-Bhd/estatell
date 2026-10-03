<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\Reservations\ReservationBookingController;
use App\Http\Controllers\Reservations\ReservationController;
use App\Http\Controllers\Reservations\ReservationFolioController;
use App\Http\Controllers\Reservations\ReservationGroupController;
use App\Http\Controllers\Reservations\ReservationUnitAddOnController;
use App\Http\Controllers\Reservations\ReservationUnitController;
use Illuminate\Support\Facades\Route;

Route::prefix('reservations')->name('reservations.')->scopeBindings()->group(function () {
    Route::middleware('can:'.PermissionEnum::ReservationsManage->value)->group(function () {
        Route::post('quote', [ReservationBookingController::class, 'quote'])->name('quote');
        Route::get('new', [ReservationBookingController::class, 'new'])->name('new');
        Route::get('walk-in', [ReservationBookingController::class, 'walkIn'])->name('walk-in');
        Route::post('/', [ReservationBookingController::class, 'store'])->name('store');
        Route::get('free-units', [ReservationBookingController::class, 'freeUnits'])->name('free-units');
    });

    Route::middleware('can:'.PermissionEnum::ReservationsView->value)->group(function () {
        Route::get('/', [ReservationController::class, 'index'])->name('index');

        Route::prefix('{reservation}')->group(function () {
            Route::get('/', [ReservationController::class, 'show'])->name('show');
            Route::get('guests', [ReservationController::class, 'guests'])->name('guests');
            Route::get('add-ons', [ReservationController::class, 'addOns'])->name('add-ons');
            Route::get('folio', [ReservationController::class, 'folio'])->name('folio');
            Route::get('activity', [ReservationController::class, 'activity'])->name('activity');

            Route::prefix('folios/{folio}')->name('folios.')->group(function () {
                Route::post('charges', [ReservationFolioController::class, 'storeCharge'])->middleware('can:'.PermissionEnum::FolioPostCharge->value)->name('charges.store');
                Route::post('lines/{line}/reversal', [ReservationFolioController::class, 'reverse'])->middleware('can:'.PermissionEnum::FolioReverse->value)->name('lines.reverse');
                Route::post('discounts/quote', [ReservationFolioController::class, 'quoteDiscount'])->middleware('can:'.PermissionEnum::FolioDiscount->value)->name('discounts.quote');
                Route::post('discounts', [ReservationFolioController::class, 'storeDiscount'])->middleware('can:'.PermissionEnum::FolioDiscount->value)->name('discounts.store');
                Route::delete('lines/{line}/discount', [ReservationFolioController::class, 'destroyDiscount'])->middleware('can:'.PermissionEnum::FolioDiscount->value)->name('discounts.destroy');
                Route::post('close', [ReservationFolioController::class, 'close'])->middleware('can:'.PermissionEnum::FolioClose->value)->name('close');
                Route::post('reopen', [ReservationFolioController::class, 'reopen'])->middleware('can:'.PermissionEnum::FolioReopen->value)->name('reopen');
                Route::post('transfers', [ReservationFolioController::class, 'transfer'])->middleware('can:'.PermissionEnum::FolioTransfer->value)->name('transfers.store');
            });
        });
    });

    Route::middleware('can:'.PermissionEnum::ReservationsManage->value)->prefix('{reservation}')->group(function () {
        Route::put('group', [ReservationGroupController::class, 'update'])->name('group.update');

        Route::prefix('units')->name('units.')->group(function () {
            Route::post('/', [ReservationUnitController::class, 'store'])->name('store');

            Route::prefix('{unit}')->group(function () {
                Route::post('guests', [ReservationUnitController::class, 'storeGuest'])->name('guests.store');
                Route::post('add-ons', [ReservationUnitAddOnController::class, 'store'])->name('add-ons.store');
                Route::delete('add-ons/{addOn}', [ReservationUnitAddOnController::class, 'destroy'])->name('add-ons.destroy');
            });
        });
    });
});
