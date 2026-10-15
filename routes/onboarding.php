<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\Onboarding\GoLiveController;
use App\Http\Controllers\Onboarding\OnboardingSkipController;
use App\Http\Controllers\Onboarding\OnboardingWaitingController;
use App\Http\Controllers\Onboarding\PropertyBasicsController;
use Illuminate\Support\Facades\Route;

Route::prefix('onboarding')->name('onboarding.')->group(function () {
    Route::get('waiting', OnboardingWaitingController::class)->name('waiting');

    Route::get('basics', [PropertyBasicsController::class, 'edit'])->name('basics.edit');
    Route::put('basics', [PropertyBasicsController::class, 'update'])->name('basics.update');

    Route::post('skips/{step}', [OnboardingSkipController::class, 'store'])->name('skips.store');
    Route::delete('skips/{step}', [OnboardingSkipController::class, 'destroy'])->name('skips.destroy');

    Route::middleware('can:'.PermissionEnum::SettingsManage->value)->group(function () {
        Route::get('go-live', [GoLiveController::class, 'show'])->name('go-live.show');
        Route::post('go-live', [GoLiveController::class, 'store'])->name('go-live.store');
    });
});
