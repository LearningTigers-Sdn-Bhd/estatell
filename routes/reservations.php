<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\Reservations\ReservationController;
use App\Http\Controllers\Reservations\ReservationGroupController;
use App\Http\Controllers\Reservations\ReservationUnitAddOnController;
use App\Http\Controllers\Reservations\ReservationUnitController;
use Illuminate\Support\Facades\Route;

Route::prefix('reservations')->name('reservations.')->scopeBindings()->group(function () {
    Route::middleware('can:'.PermissionEnum::ReservationsView->value)->group(function () {
        Route::get('/', [ReservationController::class, 'index'])->name('index');

        Route::prefix('{reservation}')->group(function () {
            Route::get('/', [ReservationController::class, 'show'])->name('show');
            Route::get('guests', [ReservationController::class, 'guests'])->name('guests');
            Route::get('add-ons', [ReservationController::class, 'addOns'])->name('add-ons');
            Route::get('activity', [ReservationController::class, 'activity'])->name('activity');
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
