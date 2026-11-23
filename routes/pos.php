<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\Pos\MealCheckInController;
use App\Http\Controllers\Pos\PosApiClientController;
use App\Http\Controllers\Pos\PosOutletController;
use App\Http\Controllers\Pos\PosPostingController;
use Illuminate\Support\Facades\Route;

/*
 * Outlets, restaurant meal check-in and POS postings, inside `/p/{property:code}` behind the `live` group. Every route
 * answers 404 while the property's POS integration feature is off. See docs/33-pos/.
 */
Route::middleware('pos-integration')->group(function () {
    Route::prefix('settings')->name('settings.')->middleware('can:'.PermissionEnum::PosManage->value)->group(function () {
        Route::prefix('pos-outlets')->name('pos-outlets.')->group(function () {
            Route::get('/', [PosOutletController::class, 'index'])->name('index');
            Route::post('/', [PosOutletController::class, 'store'])->name('store');
            Route::put('{outlet}', [PosOutletController::class, 'update'])->whereNumber('outlet')->name('update');
        });

        Route::prefix('pos-api-clients')->name('pos-api-clients.')->group(function () {
            Route::get('/', [PosApiClientController::class, 'index'])->name('index');
            Route::post('/', [PosApiClientController::class, 'store'])->name('store');
            Route::post('{client}/revoke', [PosApiClientController::class, 'revoke'])->whereNumber('client')->name('revoke');
        });
    });

    Route::prefix('finance/pos-postings')->name('finance.pos-postings.')->group(function () {
        Route::middleware('can:'.PermissionEnum::PosView->value)->group(function () {
            Route::get('/', [PosPostingController::class, 'index'])->name('index');
            Route::get('{posting}', [PosPostingController::class, 'show'])->whereNumber('posting')->name('show');
        });

        Route::post('{posting}/resolve', [PosPostingController::class, 'resolve'])->whereNumber('posting')->middleware('can:'.PermissionEnum::PosResolve->value)->name('resolve');
    });

    Route::prefix('restaurant/meal-check-in')->name('restaurant.meal-check-in.')->middleware('can:'.PermissionEnum::MealsRedeem->value)->group(function () {
        Route::get('/', [MealCheckInController::class, 'index'])->name('index');
        Route::post('/', [MealCheckInController::class, 'store'])->name('store');
        Route::delete('{redemption}', [MealCheckInController::class, 'destroy'])->whereNumber('redemption')->name('destroy');
    });
});
