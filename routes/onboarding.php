<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\Onboarding\BasicRateController;
use App\Http\Controllers\Onboarding\CompanyController;
use App\Http\Controllers\Onboarding\GoLiveController;
use App\Http\Controllers\Onboarding\OnboardingSkipController;
use App\Http\Controllers\Onboarding\OnboardingWaitingController;
use App\Http\Controllers\Onboarding\PaymentMethodController;
use App\Http\Controllers\Onboarding\PropertyBasicsController;
use App\Http\Controllers\Onboarding\RoomController;
use App\Http\Controllers\Onboarding\RoomTypeController;
use App\Http\Controllers\Onboarding\TaxController;
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

    Route::get('taxes', [TaxController::class, 'edit'])->name('taxes.edit');
    Route::put('taxes', [TaxController::class, 'update'])->name('taxes.update');
    Route::post('taxes/preview', [TaxController::class, 'preview'])->name('taxes.preview');
    Route::delete('taxes/{tax}', [TaxController::class, 'destroy'])->name('taxes.destroy');

    Route::get('payment-methods', [PaymentMethodController::class, 'edit'])->name('payment-methods.edit');
    Route::put('payment-methods', [PaymentMethodController::class, 'update'])->name('payment-methods.update');

    Route::get('companies', [CompanyController::class, 'edit'])->name('companies.edit');
    Route::put('companies', [CompanyController::class, 'update'])->name('companies.update');
    Route::delete('companies/{company}', [CompanyController::class, 'destroy'])->name('companies.destroy');
    Route::post('companies/import', [CompanyController::class, 'import'])->name('companies.import');
    Route::get('companies/import-template.{format}', [CompanyController::class, 'template'])->name('companies.template');

    Route::get('basic-rate', [BasicRateController::class, 'edit'])->name('basic-rate.edit');
    Route::put('basic-rate', [BasicRateController::class, 'update'])->name('basic-rate.update');
    Route::post('basic-rate/preview', [BasicRateController::class, 'preview'])->name('basic-rate.preview');

    Route::post('skips/{step}', [OnboardingSkipController::class, 'store'])->name('skips.store');
    Route::delete('skips/{step}', [OnboardingSkipController::class, 'destroy'])->name('skips.destroy');

    Route::middleware('can:'.PermissionEnum::SettingsManage->value)->group(function () {
        Route::get('go-live', [GoLiveController::class, 'show'])->name('go-live.show');
        Route::post('go-live', [GoLiveController::class, 'store'])->name('go-live.store');
    });
});
