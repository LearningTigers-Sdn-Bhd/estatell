<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\Housekeeping\HousekeepingBoardController;
use App\Http\Controllers\Housekeeping\HousekeepingExportController;
use Illuminate\Support\Facades\Route;

Route::prefix('housekeeping')->name('housekeeping.')->scopeBindings()->group(function () {
    Route::get('/', [HousekeepingBoardController::class, 'index'])->middleware('can:'.PermissionEnum::HousekeepingView->value)->name('index');

    Route::middleware('can:'.PermissionEnum::HousekeepingView->value)->group(function () {
        Route::post('pdf', [HousekeepingExportController::class, 'pdf'])->name('pdf');
        Route::post('spreadsheet', [HousekeepingExportController::class, 'spreadsheet'])->name('spreadsheet');
    });

    Route::post('bulk', [HousekeepingBoardController::class, 'bulk'])->middleware('can:'.PermissionEnum::HousekeepingUpdate->value)->name('bulk');

    Route::prefix('rooms/{room}')->name('rooms.')->middleware('can:'.PermissionEnum::HousekeepingUpdate->value)->group(function () {
        Route::put('status', [HousekeepingBoardController::class, 'status'])->name('status');
        Route::put('assignee', [HousekeepingBoardController::class, 'assignee'])->name('assignee');
        Route::put('priority', [HousekeepingBoardController::class, 'priority'])->name('priority');
        Route::put('dnd', [HousekeepingBoardController::class, 'dnd'])->name('dnd');
        Route::put('remark', [HousekeepingBoardController::class, 'remark'])->name('remark');
        Route::put('maintenance', [HousekeepingBoardController::class, 'maintenance'])->name('maintenance');
    });
});
