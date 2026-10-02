<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\Reservations\ReservationController;
use Illuminate\Support\Facades\Route;

Route::prefix('reservations')->name('reservations.')->scopeBindings()->group(function () {
    Route::middleware('can:'.PermissionEnum::ReservationsView->value)->group(function () {
        Route::get('/', [ReservationController::class, 'index'])->name('index');
    });
});
