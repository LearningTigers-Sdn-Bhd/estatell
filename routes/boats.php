<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\Boats\BoatBoardController;
use App\Http\Controllers\Boats\BoatBoardTripController;
use App\Http\Controllers\Boats\BoatExportController;
use App\Http\Controllers\Boats\BoatSailingController;
use App\Http\Controllers\Boats\BoatTripNoteController;
use App\Http\Controllers\Boats\BoatTripTickController;
use Illuminate\Support\Facades\Route;

Route::prefix('boats')->name('boats.')->middleware('boats')->group(function () {
    Route::get('/', [BoatBoardController::class, 'index'])->middleware('can:'.PermissionEnum::ReservationsView->value)->name('index');

    Route::middleware('can:'.PermissionEnum::ReservationsView->value)->group(function () {
        Route::post('pdf', [BoatExportController::class, 'pdf'])->name('pdf');
        Route::post('spreadsheet', [BoatExportController::class, 'spreadsheet'])->name('spreadsheet');
    });

    Route::middleware('can:'.PermissionEnum::ReservationsManage->value)->group(function () {
        Route::put('sailings', [BoatSailingController::class, 'save'])->name('sailings.save');
        Route::post('sailings', [BoatSailingController::class, 'store'])->name('sailings.store');
        Route::delete('sailings/{sailing}', [BoatSailingController::class, 'destroy'])->whereNumber('sailing')->name('sailings.destroy');
        Route::put('departures/{departure}/cancel', [BoatSailingController::class, 'cancel'])->whereNumber('departure')->name('departures.cancel');
        Route::put('departures/{departure}/reset', [BoatSailingController::class, 'reset'])->whereNumber('departure')->name('departures.reset');
        Route::post('trips/move', [BoatBoardTripController::class, 'move'])->name('trips.move');
        Route::post('trips/tick', [BoatBoardTripController::class, 'tick'])->name('trips.tick');
        Route::put('trips/{trip}/tick', BoatTripTickController::class)->whereNumber('trip')->name('trips.set-tick');
        Route::put('trips/{trip}/note', BoatTripNoteController::class)->whereNumber('trip')->name('trips.note');
    });
});
