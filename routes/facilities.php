<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\Facilities\FacilityBookingController;
use Illuminate\Support\Facades\Route;

/*
 * Facility bookings, on events and reservations. Settings › Facilities is in settings.php; the reservation page's
 * Facilities tab is in reservations.php.
 * See docs/33-facilities/04-facility-bookings.md#routes.
 */
Route::prefix('facility-bookings')
    ->name('facility-bookings.')
    ->middleware(['facilities', 'can:'.PermissionEnum::FacilitiesBook->value])
    ->scopeBindings()
    ->group(function () {
        Route::post('/', [FacilityBookingController::class, 'store'])->name('store');
        Route::post('quote', [FacilityBookingController::class, 'quote'])->name('quote');
        Route::put('{facilityBooking}', [FacilityBookingController::class, 'update'])->name('update');
        Route::post('{facilityBooking}/post', [FacilityBookingController::class, 'post'])
            ->middleware('can:'.PermissionEnum::FolioPostCharge->value)
            ->name('post');
        Route::post('{facilityBooking}/cancel', [FacilityBookingController::class, 'cancel'])->name('cancel');
        Route::post('reservations/{reservation}/folios', [FacilityBookingController::class, 'storeFolio'])
            ->middleware('can:'.PermissionEnum::FolioTransfer->value)
            ->name('folios.store');
    });
