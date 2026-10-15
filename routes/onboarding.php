<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\Onboarding\GoLiveController;
use App\Http\Controllers\Onboarding\OnboardingSkipController;
use App\Http\Controllers\Onboarding\OnboardingWaitingController;
use App\Http\Controllers\Onboarding\PropertyBasicsController;
use App\Http\Controllers\Onboarding\RoomController;
use App\Http\Controllers\Onboarding\RoomTypeController;
use Illuminate\Support\Facades\Route;

Route::prefix('onboarding')->name('onboarding.')->group(function () {
    Route::get('waiting', OnboardingWaitingController::class)->name('waiting');

    Route::get('basics', [PropertyBasicsController::class, 'edit'])->name('basics.edit');
    Route::put('basics', [PropertyBasicsController::class, 'update'])->name('basics.update');

    Route::get('room-types', [RoomTypeController::class, 'edit'])->name('room-types.edit');
    Route::put('room-types', [RoomTypeController::class, 'update'])->name('room-types.update');

    Route::get('rooms', [RoomController::class, 'edit'])->name('rooms.edit');
    Route::put('rooms', [RoomController::class, 'update'])->name('rooms.update');
    Route::delete('rooms/{room}', [RoomController::class, 'destroy'])->name('rooms.destroy');

    Route::post('skips/{step}', [OnboardingSkipController::class, 'store'])->name('skips.store');
    Route::delete('skips/{step}', [OnboardingSkipController::class, 'destroy'])->name('skips.destroy');

    Route::middleware('can:'.PermissionEnum::SettingsManage->value)->group(function () {
        Route::get('go-live', [GoLiveController::class, 'show'])->name('go-live.show');
        Route::post('go-live', [GoLiveController::class, 'store'])->name('go-live.store');
    });
});
