<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\Boats\BoatBoardController;
use App\Http\Controllers\Boats\BoatBoardTripController;
use App\Http\Controllers\Boats\BoatSailingController;
use Illuminate\Support\Facades\Route;

Route::prefix('boats')->name('boats.')->middleware('boats')->group(function () {
    Route::get('/', [BoatBoardController::class, 'index'])->middleware('can:'.PermissionEnum::ReservationsView->value)->name('index');

    Route::middleware('can:'.PermissionEnum::ReservationsManage->value)->group(function () {
        Route::put('sailings', [BoatSailingController::class, 'save'])->name('sailings.save');
        Route::post('sailings', [BoatSailingController::class, 'store'])->name('sailings.store');
        Route::delete('sailings/{sailing}', [BoatSailingController::class, 'destroy'])->whereNumber('sailing')->name('sailings.destroy');
        Route::put('departures/{departure}/reset', [BoatSailingController::class, 'reset'])->whereNumber('departure')->name('departures.reset');
        Route::post('trips/move', [BoatBoardTripController::class, 'move'])->name('trips.move');
        Route::post('trips/tick', [BoatBoardTripController::class, 'tick'])->name('trips.tick');
    });
});
