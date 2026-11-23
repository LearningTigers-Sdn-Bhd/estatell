<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\Transfers\TransferRunSheetController;
use Illuminate\Support\Facades\Route;

Route::prefix('transfers')->name('transfers.')->middleware('transfers')->group(function () {
    Route::get('/', [TransferRunSheetController::class, 'index'])->middleware('can:'.PermissionEnum::ReservationsView->value)->name('index');
    Route::put('{transfer}/assign', [TransferRunSheetController::class, 'assign'])->whereNumber('transfer')->middleware('can:'.PermissionEnum::ReservationsManage->value)->name('assign');
});
