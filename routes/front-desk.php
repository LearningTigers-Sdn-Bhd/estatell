<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\FrontDesk\StayViewController;
use App\Http\Controllers\Rooms\RoomBlockController;
use Illuminate\Support\Facades\Route;

Route::prefix('front-desk')->name('front-desk.')->group(function () {
    Route::get('stays/{reservation}/folio', [StayViewController::class, 'folio'])->middleware(['can:'.PermissionEnum::FrontDeskView->value, 'can:'.PermissionEnum::ReservationsView->value])->scopeBindings()->name('stays.folio');
    Route::get('timeline', [StayViewController::class, 'timeline'])->middleware('can:'.PermissionEnum::FrontDeskView->value)->name('timeline');

    Route::prefix('room-blocks')->name('room-blocks.')->middleware('can:'.PermissionEnum::RoomsBlock->value)->scopeBindings()->group(function () {
        Route::get('/', [RoomBlockController::class, 'index'])->name('index');
        Route::post('/', [RoomBlockController::class, 'store'])->name('store');
        Route::put('{roomBlock}', [RoomBlockController::class, 'update'])->name('update');
        Route::post('{roomBlock}/end', [RoomBlockController::class, 'end'])->name('end');
        Route::delete('{roomBlock}', [RoomBlockController::class, 'destroy'])->name('destroy');
    });
});
