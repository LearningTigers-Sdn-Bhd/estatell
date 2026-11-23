<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\Facilities\FacilityBookingController;
use Illuminate\Support\Facades\Route;

/*
 * Facility bookings, on events (and on reservations with the next item). Settings › Facilities is in settings.php.
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
        Route::post('{facilityBooking}/cancel', [FacilityBookingController::class, 'cancel'])->name('cancel');
    });
