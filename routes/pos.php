<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\Pos\MealCheckInController;
use App\Http\Controllers\Pos\PosApiClientController;
use App\Http\Controllers\Pos\PosCfdController;
use App\Http\Controllers\Pos\PosClockController;
use App\Http\Controllers\Pos\PosDiningOptionController;
use App\Http\Controllers\Pos\PosFloorController;
use App\Http\Controllers\Pos\PosInventoryController;
use App\Http\Controllers\Pos\PosKitchenController;
use App\Http\Controllers\Pos\PosKitchenSettingsController;
use App\Http\Controllers\Pos\PosMenuController;
use App\Http\Controllers\Pos\PosModifierController;
use App\Http\Controllers\Pos\PosOfflineController;
use App\Http\Controllers\Pos\PosOrderController;
use App\Http\Controllers\Pos\PosOrderReceiptController;
use App\Http\Controllers\Pos\PosOutletController;
use App\Http\Controllers\Pos\PosOutletSettingsController;
use App\Http\Controllers\Pos\PosPinController;
use App\Http\Controllers\Pos\PosPostingController;
use App\Http\Controllers\Pos\PosRegisterController;
use App\Http\Controllers\Pos\PosRoomQrController;
use App\Http\Controllers\Pos\PosSalesReportController;
use App\Http\Controllers\Pos\PosScheduleController;
use App\Http\Controllers\Pos\PosShiftController;
use App\Http\Controllers\Pos\PosShiftReportController;
use App\Http\Controllers\Pos\PosStaffPinController;
use App\Http\Controllers\Pos\PosStaffSettingsController;
use App\Http\Controllers\Pos\PosTableQrController;
use App\Http\Controllers\Pos\PosTerminalDeviceController;
use App\Http\Controllers\Pos\PosTerminalModeController;
use Illuminate\Support\Facades\Route;

/*
 * Point of Sale, inside `/p/{property:code}` behind the `live` group. Every route answers 404 while the property's POS
 * feature is off. Routes under `/pos` run `ResolvePosActor` and authorize the PIN-selected actor with `pos.can:*`, never
 * the browser user with `can:`.
 */
Route::middleware('pos')->group(function () {
    Route::prefix('settings/pos-outlets')->name('settings.pos-outlets.')->middleware('can:'.PermissionEnum::PosManage->value)->group(function () {
        Route::get('/', [PosOutletController::class, 'index'])->name('index');
        Route::post('/', [PosOutletController::class, 'store'])->name('store');
        Route::put('{outlet}', [PosOutletController::class, 'update'])->whereNumber('outlet')->name('update');
    });

    Route::prefix('settings/pos-api-clients')->name('settings.pos-api-clients.')->middleware('can:'.PermissionEnum::PosManage->value)->group(function () {
        Route::get('/', [PosApiClientController::class, 'index'])->name('index');
        Route::post('/', [PosApiClientController::class, 'store'])->name('store');
        Route::post('{client}/revoke', [PosApiClientController::class, 'revoke'])->whereNumber('client')->name('revoke');
    });

    Route::prefix('restaurant/meal-check-in')->name('restaurant.meal-check-in.')->middleware('can:'.PermissionEnum::MealsRedeem->value)->group(function () {
        Route::get('/', [MealCheckInController::class, 'index'])->name('index');
        Route::post('/', [MealCheckInController::class, 'store'])->name('store');
        Route::delete('{redemption}', [MealCheckInController::class, 'destroy'])->whereNumber('redemption')->name('destroy');
    });

    Route::prefix('finance/pos-postings')->name('finance.pos-postings.')->group(function () {
        Route::middleware('can:'.PermissionEnum::PosView->value)->group(function () {
            Route::get('/', [PosPostingController::class, 'index'])->name('index');
            Route::get('{posting}', [PosPostingController::class, 'show'])->whereNumber('posting')->name('show');
        });

        Route::post('{posting}/resolve', [PosPostingController::class, 'resolve'])->whereNumber('posting')->middleware('can:'.PermissionEnum::PosResolve->value)->name('resolve');
    });

    Route::prefix('pos')->name('pos.')->group(function () {
        Route::post('terminal-mode', [PosTerminalModeController::class, 'enter'])->middleware('can:'.PermissionEnum::PosSell->value)->name('terminal-mode.enter');
        Route::post('terminal-mode/exit', [PosTerminalModeController::class, 'exit'])->middleware('throttle:6,1')->name('terminal-mode.exit');

        Route::post('pin/switch', [PosPinController::class, 'switch'])->middleware(['throttle:30,1', 'pos.actor', 'pos.device'])->name('pin.switch');

        // Everything below acts as the PIN-selected staff member (`ResolvePosActor`), never as the browser user.
        Route::middleware('pos.actor')->group(function () {
            Route::get('', [PosRegisterController::class, 'index'])->name('index');
            Route::post('clock', PosClockController::class)->middleware('throttle:30,1')->name('clock');

            Route::middleware('pos.can:'.PermissionEnum::PosSell->value)->group(function () {
                Route::get('outlets/{outlet}/terminal', [PosRegisterController::class, 'terminal'])->whereNumber('outlet')->name('terminal');
                Route::get('outlets/{outlet}/floor-version', [PosRegisterController::class, 'floorVersion'])->whereNumber('outlet')->name('floor-version');
                Route::get('in-house-guests', [PosRegisterController::class, 'guests'])->name('in-house-guests');

                Route::post('orders', [PosOrderController::class, 'store'])->name('orders.store');
                Route::prefix('orders/{order}')->whereNumber(['order', 'line'])->name('orders.')->group(function () {
                    Route::post('lines', [PosOrderController::class, 'addLine'])->name('lines.store');
                    Route::patch('lines/{line}', [PosOrderController::class, 'updateLine'])->name('lines.update');
                    Route::post('lines/{line}/void', [PosOrderController::class, 'voidLine'])->name('lines.void');
                    Route::post('fire', [PosOrderController::class, 'fire'])->name('fire');
                    Route::post('course', [PosOrderController::class, 'course'])->name('course');
                    Route::post('lines/{line}/split', [PosOrderController::class, 'splitLine'])->name('lines.split');
                    Route::post('lines/{line}/place', [PosOrderController::class, 'placeLine'])->name('lines.place');
                    Route::post('split', [PosOrderController::class, 'split'])->name('split');
                    Route::post('move', [PosOrderController::class, 'move'])->name('move');
                    Route::post('merge', [PosOrderController::class, 'merge'])->name('merge');
                    Route::post('void', [PosOrderController::class, 'void'])->name('void');
                    Route::post('discount', [PosOrderController::class, 'discount'])->name('discount');
                    Route::post('prebill', [PosOrderController::class, 'prebill'])->name('prebill');
                    Route::post('dining-option', [PosOrderController::class, 'diningOption'])->name('dining-option');
                    Route::post('room', [PosOrderController::class, 'attachRoom'])->name('room');
                    Route::post('table', [PosOrderController::class, 'transferTable'])->name('table');
                    Route::post('settle', [PosOrderController::class, 'settle'])->name('settle');
                    Route::post('refund', [PosOrderController::class, 'refund'])->name('refund');
                    Route::post('change-payment', [PosOrderController::class, 'changePayment'])->name('change-payment');
                });

                Route::get('cfd/{register}', [PosCfdController::class, 'show'])->whereNumber('register')->name('cfd.show');
                Route::get('cfd/{register}/version', [PosCfdController::class, 'version'])->whereNumber('register')->name('cfd.version');
                Route::post('cfd/{register}/state', [PosCfdController::class, 'state'])->whereNumber('register')->name('cfd.state');

                Route::post('tables/{table}/qr', [PosTableQrController::class, 'issue'])->whereNumber('table')->name('tables.qr');
                Route::get('offline', [PosOfflineController::class, 'show'])->name('offline.show');
                Route::get('offline/snapshot', [PosOfflineController::class, 'snapshot'])->name('offline.snapshot');
                Route::post('offline-sync', [PosOfflineController::class, 'sync'])->middleware('throttle:60,1')->name('offline.sync');
            });

            Route::middleware('pos.can:'.PermissionEnum::PosKds->value)->group(function () {
                Route::get('kds', [PosKitchenController::class, 'index'])->name('kds.index');
                Route::get('kds/{station}', [PosKitchenController::class, 'show'])->whereNumber('station')->name('kds.show');
                Route::get('kds/{station}/version', [PosKitchenController::class, 'version'])->whereNumber('station')->name('kds.version');
                Route::patch('kds/lines/{line}/status', [PosKitchenController::class, 'updateLine'])->whereNumber('line')->name('kds.lines.status');
                Route::post('kds/{station}/orders/{order}/bump', [PosKitchenController::class, 'bump'])->whereNumber(['station', 'order'])->name('kds.orders.bump');
                Route::post('kds/{station}/orders/{order}/recall', [PosKitchenController::class, 'recall'])->whereNumber(['station', 'order'])->name('kds.orders.recall');
                Route::get('ods/{outlet}', [PosKitchenController::class, 'pickup'])->whereNumber('outlet')->name('ods.show');
                Route::get('ods/{outlet}/version', [PosKitchenController::class, 'pickupVersion'])->whereNumber('outlet')->name('ods.version');
            });

            Route::middleware('pos.can:'.PermissionEnum::PosShift->value)->group(function () {
                Route::post('orders/{order}/transfer-register', [PosOrderController::class, 'transfer'])->whereNumber('order')->name('orders.transfer-register');
                Route::post('shifts/open', [PosShiftController::class, 'open'])->name('shifts.open');
                Route::prefix('shifts/{shift}')->whereNumber('shift')->name('shifts.')->group(function () {
                    Route::post('movements', [PosShiftController::class, 'movement'])->name('movements.store');
                    Route::post('begin-close', [PosShiftController::class, 'beginClose'])->name('begin-close');
                    Route::post('cancel-close', [PosShiftController::class, 'cancelClose'])->name('cancel-close');
                    Route::post('count', [PosShiftController::class, 'count'])->name('count');
                    Route::post('close', [PosShiftController::class, 'close'])->name('close');
                });
            });
        });
    });

    Route::post('pos/room-qr/{unit}/regenerate', [PosRoomQrController::class, 'regenerate'])->whereNumber('unit')->middleware('can:'.PermissionEnum::FrontDeskCheckIn->value)->name('pos.room-qr.regenerate');

    Route::prefix('finance')->name('finance.')->group(function () {
        Route::get('pos-orders', [PosOrderReceiptController::class, 'index'])->middleware('can:'.PermissionEnum::PosView->value)->name('pos-orders.index');
        Route::get('pos-orders/{order}', [PosOrderReceiptController::class, 'show'])->whereNumber('order')->middleware('can:'.PermissionEnum::PosView->value)->name('pos-orders.show');
        Route::get('pos-orders/{order}/receipt', [PosOrderReceiptController::class, 'receipt'])->whereNumber('order')->middleware('can:'.PermissionEnum::PosView->value)->name('pos-orders.receipt');
        Route::post('pos-orders/{order}/refund', [PosOrderReceiptController::class, 'refund'])->whereNumber('order')->middleware('can:'.PermissionEnum::PosRefund->value)->name('pos-orders.refund');
        Route::post('pos-orders/{order}/change-payment', [PosOrderReceiptController::class, 'changePayment'])->whereNumber('order')->middleware('can:'.PermissionEnum::PosRefund->value)->name('pos-orders.change-payment');
        Route::post('pos-orders/{order}/einvoice', [PosOrderReceiptController::class, 'einvoice'])->whereNumber('order')->middleware(['einvoicing', 'can:'.PermissionEnum::EInvoiceManage->value])->name('pos-orders.einvoice');
        Route::get('pos-shifts', [PosShiftReportController::class, 'index'])->middleware('can:'.PermissionEnum::PosReports->value)->name('pos-shifts.index');
        Route::get('pos-shifts/{shift}', [PosShiftReportController::class, 'show'])->whereNumber('shift')->middleware('can:'.PermissionEnum::PosReports->value)->name('pos-shifts.show');
    });

    Route::get('reports/pos-sales', PosSalesReportController::class)->middleware('can:'.PermissionEnum::PosReports->value)->name('reports.pos-sales');

    Route::prefix('settings/pos')->name('settings.pos.')->middleware('can:'.PermissionEnum::PosManage->value)->group(function () {
        Route::post('devices', [PosTerminalDeviceController::class, 'store'])->name('devices.store');
        Route::post('devices/{device}/revoke', [PosTerminalDeviceController::class, 'revoke'])->whereNumber('device')->name('devices.revoke');
        Route::post('staff-pins/{user}/revoke', [PosStaffPinController::class, 'revoke'])->whereNumber('user')->name('staff-pins.revoke');
        Route::get('staff-pins', [PosStaffSettingsController::class, 'index'])->name('staff-pins.index');
        Route::post('void-reasons', [PosStaffSettingsController::class, 'storeVoidReason'])->name('void-reasons.store');
        Route::put('void-reasons/{reason}', [PosStaffSettingsController::class, 'updateVoidReason'])->whereNumber('reason')->name('void-reasons.update');
        Route::get('outlets', [PosOutletSettingsController::class, 'index'])->name('outlets.index');
        Route::put('outlets/{outlet}', [PosOutletSettingsController::class, 'update'])->whereNumber('outlet')->name('outlets.update');
        Route::post('outlets/{outlet}/cfd-qr', [PosOutletSettingsController::class, 'storeCfdQr'])->whereNumber('outlet')->name('outlets.cfd-qr.store');
        Route::delete('outlets/{outlet}/cfd-qr', [PosOutletSettingsController::class, 'destroyCfdQr'])->whereNumber('outlet')->name('outlets.cfd-qr.destroy');
        Route::post('outlets/{outlet}/registers', [PosOutletSettingsController::class, 'storeRegister'])->whereNumber('outlet')->name('registers.store');
        Route::put('outlets/{outlet}/registers/{register}', [PosOutletSettingsController::class, 'updateRegister'])->whereNumber(['outlet', 'register'])->name('registers.update');
        Route::post('outlets/{outlet}/payment-methods', [PosOutletSettingsController::class, 'storePaymentMethod'])->whereNumber('outlet')->name('payment-methods.store');
        Route::put('outlets/{outlet}/payment-methods/{method}', [PosOutletSettingsController::class, 'updatePaymentMethod'])->whereNumber(['outlet', 'method'])->name('payment-methods.update');
    });

    Route::prefix('settings/pos')->name('settings.pos.')->middleware('can:'.PermissionEnum::PosInventory->value)->group(function () {
        Route::get('inventory', [PosInventoryController::class, 'index'])->name('inventory.index');
        Route::post('ingredients', [PosInventoryController::class, 'storeIngredient'])->name('ingredients.store');
        Route::put('ingredients/{ingredient}', [PosInventoryController::class, 'updateIngredient'])->whereNumber('ingredient')->name('ingredients.update');
        Route::put('variants/{variant}/recipe', [PosInventoryController::class, 'saveRecipe'])->whereNumber('variant')->name('recipes.update');
        Route::post('outlets/{outlet}/purchases', [PosInventoryController::class, 'receive'])->whereNumber('outlet')->name('purchases.store');
        Route::post('outlets/{outlet}/waste', [PosInventoryController::class, 'waste'])->whereNumber('outlet')->name('waste.store');
        Route::post('transfers', [PosInventoryController::class, 'transfer'])->name('transfers.store');
        Route::post('outlets/{outlet}/stocktakes', [PosInventoryController::class, 'startStocktake'])->whereNumber('outlet')->name('stocktakes.store');
        Route::put('stocktakes/{stocktake}/ingredients/{ingredient}', [PosInventoryController::class, 'count'])->whereNumber(['stocktake', 'ingredient'])->name('stocktakes.count');
        Route::post('stocktakes/{stocktake}/close', [PosInventoryController::class, 'closeStocktake'])->whereNumber('stocktake')->name('stocktakes.close');
    });

    Route::prefix('settings/pos')->name('settings.pos.')->middleware('can:'.PermissionEnum::PosMenu->value)->group(function () {
        Route::get('floors', [PosFloorController::class, 'index'])->name('floors.index');
        Route::post('outlets/{outlet}/floors', [PosFloorController::class, 'storeFloor'])->whereNumber('outlet')->name('floors.store');
        Route::put('floors/{floor}', [PosFloorController::class, 'updateFloor'])->whereNumber('floor')->name('floors.update');
        Route::post('floors/{floor}/tables', [PosFloorController::class, 'batchTables'])->whereNumber('floor')->name('tables.batch');
        Route::post('floors/{floor}/table', [PosFloorController::class, 'storeTable'])->whereNumber('floor')->name('tables.store');
        Route::put('tables/{table}', [PosFloorController::class, 'updateTable'])->whereNumber('table')->name('tables.update');
        Route::get('kitchen', [PosKitchenSettingsController::class, 'index'])->name('kitchen.index');
        Route::post('outlets/{outlet}/kitchen-stations', [PosKitchenSettingsController::class, 'storeStation'])->whereNumber('outlet')->name('kitchen-stations.store');
        Route::put('kitchen-stations/{station}', [PosKitchenSettingsController::class, 'updateStation'])->whereNumber('station')->name('kitchen-stations.update');
        Route::put('outlets/{outlet}/kitchen-routing', [PosKitchenSettingsController::class, 'updateRouting'])->whereNumber('outlet')->name('kitchen-routing.update');
        Route::get('menu', [PosMenuController::class, 'index'])->name('menu.index');
        Route::post('categories', [PosMenuController::class, 'storeCategory'])->name('categories.store');
        Route::put('categories/{category}', [PosMenuController::class, 'updateCategory'])->whereNumber('category')->name('categories.update');
        Route::post('items', [PosMenuController::class, 'storeItem'])->name('items.store');
        Route::put('items/{item}', [PosMenuController::class, 'updateItem'])->whereNumber('item')->name('items.update');
        Route::put('outlets/{outlet}/variants/{variant}', [PosMenuController::class, 'updateOutletItem'])->whereNumber(['outlet', 'variant'])->name('outlet-items.update');
        Route::get('schedules', [PosScheduleController::class, 'index'])->name('schedules.index');
        Route::post('outlets/{outlet}/schedules', [PosScheduleController::class, 'storeSchedule'])->whereNumber('outlet')->name('schedules.store');
        Route::put('schedules/{schedule}', [PosScheduleController::class, 'updateSchedule'])->whereNumber('schedule')->name('schedules.update');
        Route::put('items/{item}/combo', [PosScheduleController::class, 'saveCombo'])->whereNumber('item')->name('items.combo');
        Route::get('modifiers', [PosModifierController::class, 'index'])->name('modifiers.index');
        Route::post('modifier-groups', [PosModifierController::class, 'store'])->name('modifier-groups.store');
        Route::put('modifier-groups/{group}', [PosModifierController::class, 'update'])->whereNumber('group')->name('modifier-groups.update');
        Route::post('modifier-options/{option}/availability', [PosModifierController::class, 'availability'])->whereNumber('option')->name('modifier-options.availability');
        Route::get('dining-options', [PosDiningOptionController::class, 'index'])->name('dining-options.index');
        Route::post('outlets/{outlet}/dining-options', [PosDiningOptionController::class, 'store'])->whereNumber('outlet')->name('dining-options.store');
        Route::put('outlets/{outlet}/dining-options/{option}', [PosDiningOptionController::class, 'update'])->whereNumber(['outlet', 'option'])->name('dining-options.update');
    });
});
