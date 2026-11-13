<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\GuestRequests\GuestRequestController;
use Illuminate\Support\Facades\Route;

Route::prefix('requests')->name('guest-requests.')->scopeBindings()->group(function () {
    Route::get('/', [GuestRequestController::class, 'index'])->middleware('can:'.PermissionEnum::GuestRequestsView->value)->name('index');

    Route::middleware('can:'.PermissionEnum::GuestRequestsManage->value)->group(function () {
        Route::post('/', [GuestRequestController::class, 'store'])->name('store');
        Route::put('{guestRequest}/assign', [GuestRequestController::class, 'assign'])->name('assign');
        Route::post('{guestRequest}/cancel', [GuestRequestController::class, 'cancel'])->name('cancel');
        Route::post('{guestRequest}/reopen', [GuestRequestController::class, 'reopen'])->name('reopen');
    });

    Route::middleware('can:'.PermissionEnum::GuestRequestsResolve->value)->group(function () {
        Route::post('{guestRequest}/start', [GuestRequestController::class, 'start'])->name('start');
        Route::post('{guestRequest}/resolve', [GuestRequestController::class, 'resolve'])->name('resolve');
    });
});
