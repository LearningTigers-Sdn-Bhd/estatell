<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\Housekeeping\HousekeepingBoardController;
use Illuminate\Support\Facades\Route;

Route::prefix('housekeeping')->name('housekeeping.')->scopeBindings()->group(function () {
    Route::get('/', [HousekeepingBoardController::class, 'index'])->middleware('can:'.PermissionEnum::HousekeepingView->value)->name('index');

    Route::prefix('rooms/{room}')->name('rooms.')->middleware('can:'.PermissionEnum::HousekeepingUpdate->value)->group(function () {
        Route::put('status', [HousekeepingBoardController::class, 'status'])->name('status');
        Route::put('assignee', [HousekeepingBoardController::class, 'assignee'])->name('assignee');
        Route::put('priority', [HousekeepingBoardController::class, 'priority'])->name('priority');
        Route::put('dnd', [HousekeepingBoardController::class, 'dnd'])->name('dnd');
        Route::put('remark', [HousekeepingBoardController::class, 'remark'])->name('remark');
        Route::put('maintenance', [HousekeepingBoardController::class, 'maintenance'])->name('maintenance');
    });
});
