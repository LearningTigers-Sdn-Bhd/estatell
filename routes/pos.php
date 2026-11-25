<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\Pos\LoyverseIntegrationController;
use App\Http\Controllers\Pos\MealCheckInController;
use App\Http\Controllers\Pos\PosApiClientController;
use App\Http\Controllers\Pos\PosOutletController;
use App\Http\Controllers\Pos\PosPostingController;
use Illuminate\Support\Facades\Route;

/*
 * Outlets, restaurant meal check-in and POS postings, inside `/p/{property:code}` behind the `live` group. Outlets and meal
 * check-in answer 404 unless the POS integration or Native POS is on; the rest need the POS integration. See docs/33-pos/.
 */
// The Loyverse page answers with a "contact support" page, not a 404, while the feature is off: a merchant who comes from the
// Loyverse App Marketplace install link must not hit a dead end. Everything else about it needs the feature.
Route::prefix('settings/pos-integrations')->name('settings.pos-integrations.')->middleware('can:'.PermissionEnum::PosManage->value)->group(function () {
    Route::get('/', [LoyverseIntegrationController::class, 'index'])->name('index');

    Route::middleware('pos-integration')->prefix('loyverse')->name('loyverse.')->group(function () {
        Route::get('connect', [LoyverseIntegrationController::class, 'connect'])->name('connect');
        Route::post('refresh-catalog', [LoyverseIntegrationController::class, 'refreshCatalog'])->name('refresh-catalog');
        Route::put('mappings', [LoyverseIntegrationController::class, 'updateMappings'])->name('mappings.update');
        Route::post('sync-guests', [LoyverseIntegrationController::class, 'syncGuests'])->name('sync-guests');
        Route::delete('/', [LoyverseIntegrationController::class, 'disconnect'])->name('disconnect');
    });
});

// Outlets and meal check-in are shared: they work while either the POS integration or Native POS is on.
Route::middleware('pos-any')->group(function () {
    Route::prefix('settings/pos-outlets')->name('settings.pos-outlets.')->middleware('can:'.PermissionEnum::PosManage->value)->group(function () {
        Route::get('/', [PosOutletController::class, 'index'])->name('index');
        Route::post('/', [PosOutletController::class, 'store'])->name('store');
        Route::put('{outlet}', [PosOutletController::class, 'update'])->whereNumber('outlet')->name('update');
    });

    Route::prefix('restaurant/meal-check-in')->name('restaurant.meal-check-in.')->middleware('can:'.PermissionEnum::MealsRedeem->value)->group(function () {
        Route::get('/', [MealCheckInController::class, 'index'])->name('index');
        Route::post('/', [MealCheckInController::class, 'store'])->name('store');
        Route::delete('{redemption}', [MealCheckInController::class, 'destroy'])->whereNumber('redemption')->name('destroy');
    });
});

Route::middleware('pos-integration')->group(function () {
    Route::prefix('settings/pos-api-clients')->name('settings.pos-api-clients.')->middleware('can:'.PermissionEnum::PosManage->value)->group(function () {
        Route::get('/', [PosApiClientController::class, 'index'])->name('index');
        Route::post('/', [PosApiClientController::class, 'store'])->name('store');
        Route::post('{client}/revoke', [PosApiClientController::class, 'revoke'])->whereNumber('client')->name('revoke');
    });

    Route::prefix('finance/pos-postings')->name('finance.pos-postings.')->group(function () {
        Route::middleware('can:'.PermissionEnum::PosView->value)->group(function () {
            Route::get('/', [PosPostingController::class, 'index'])->name('index');
            Route::get('{posting}', [PosPostingController::class, 'show'])->whereNumber('posting')->name('show');
        });

        Route::post('{posting}/resolve', [PosPostingController::class, 'resolve'])->whereNumber('posting')->middleware('can:'.PermissionEnum::PosResolve->value)->name('resolve');
    });
});
