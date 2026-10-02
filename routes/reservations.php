<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\Reservations\ReservationController;
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
});
