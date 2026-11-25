<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\Pos\PosClockController;
use App\Http\Controllers\Pos\PosDiningOptionController;
use App\Http\Controllers\Pos\PosFloorController;
use App\Http\Controllers\Pos\PosMenuController;
use App\Http\Controllers\Pos\PosModifierController;
use App\Http\Controllers\Pos\PosOrderController;
use App\Http\Controllers\Pos\PosOrderReceiptController;
use App\Http\Controllers\Pos\PosOutletSettingsController;
use App\Http\Controllers\Pos\PosPinController;
use App\Http\Controllers\Pos\PosRegisterController;
use App\Http\Controllers\Pos\PosSalesReportController;
use App\Http\Controllers\Pos\PosScheduleController;
use App\Http\Controllers\Pos\PosShiftController;
use App\Http\Controllers\Pos\PosShiftReportController;
use App\Http\Controllers\Pos\PosStaffPinController;
use App\Http\Controllers\Pos\PosStaffSettingsController;
use App\Http\Controllers\Pos\PosTerminalDeviceController;
use App\Http\Controllers\Pos\PosTerminalModeController;
use Illuminate\Support\Facades\Route;

/*
 * Native POS, inside `/p/{property:code}` behind the `live` group. Every route answers 404 while the property's Native POS
 * feature is off. Routes under `/pos` run `ResolvePosActor` and authorize the PIN-selected actor with `pos.can:*`, never the
 * browser user with `can:`. See docs/34-native-pos/.
 */
Route::middleware('native-pos')->group(function () {
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
                Route::get('in-house-guests', [PosRegisterController::class, 'guests'])->name('in-house-guests');

                Route::post('orders', [PosOrderController::class, 'store'])->name('orders.store');
                Route::prefix('orders/{order}')->whereNumber(['order', 'line'])->name('orders.')->group(function () {
                    Route::post('lines', [PosOrderController::class, 'addLine'])->name('lines.store');
                    Route::patch('lines/{line}', [PosOrderController::class, 'updateLine'])->name('lines.update');
                    Route::post('lines/{line}/void', [PosOrderController::class, 'voidLine'])->name('lines.void');
                    Route::post('fire', [PosOrderController::class, 'fire'])->name('fire');
                    Route::post('course', [PosOrderController::class, 'course'])->name('course');
                    Route::post('lines/{line}/split', [PosOrderController::class, 'splitLine'])->name('lines.split');
                    Route::post('split', [PosOrderController::class, 'split'])->name('split');
                    Route::post('move', [PosOrderController::class, 'move'])->name('move');
                    Route::post('merge', [PosOrderController::class, 'merge'])->name('merge');
                    Route::post('void', [PosOrderController::class, 'void'])->name('void');
                    Route::post('discount', [PosOrderController::class, 'discount'])->name('discount');
                    Route::post('prebill', [PosOrderController::class, 'prebill'])->name('prebill');
                    Route::post('dining-option', [PosOrderController::class, 'diningOption'])->name('dining-option');
                    Route::post('settle', [PosOrderController::class, 'settle'])->name('settle');
                    Route::post('refund', [PosOrderController::class, 'refund'])->name('refund');
                    Route::post('change-payment', [PosOrderController::class, 'changePayment'])->name('change-payment');
                });
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
        Route::get('floors', [PosFloorController::class, 'index'])->name('floors.index');
        Route::post('outlets/{outlet}/floors', [PosFloorController::class, 'storeFloor'])->whereNumber('outlet')->name('floors.store');
        Route::put('floors/{floor}', [PosFloorController::class, 'updateFloor'])->whereNumber('floor')->name('floors.update');
        Route::post('floors/{floor}/tables', [PosFloorController::class, 'batchTables'])->whereNumber('floor')->name('tables.batch');
        Route::put('tables/{table}', [PosFloorController::class, 'updateTable'])->whereNumber('table')->name('tables.update');
        Route::get('outlets', [PosOutletSettingsController::class, 'index'])->name('outlets.index');
        Route::put('outlets/{outlet}', [PosOutletSettingsController::class, 'update'])->whereNumber('outlet')->name('outlets.update');
        Route::post('outlets/{outlet}/registers', [PosOutletSettingsController::class, 'storeRegister'])->whereNumber('outlet')->name('registers.store');
        Route::put('outlets/{outlet}/registers/{register}', [PosOutletSettingsController::class, 'updateRegister'])->whereNumber(['outlet', 'register'])->name('registers.update');
    });

    Route::prefix('settings/pos')->name('settings.pos.')->middleware('can:'.PermissionEnum::PosMenu->value)->group(function () {
        Route::get('menu', [PosMenuController::class, 'index'])->name('menu.index');
        Route::post('categories', [PosMenuController::class, 'storeCategory'])->name('categories.store');
        Route::put('categories/{category}', [PosMenuController::class, 'updateCategory'])->whereNumber('category')->name('categories.update');
        Route::post('items', [PosMenuController::class, 'storeItem'])->name('items.store');
        Route::put('items/{item}', [PosMenuController::class, 'updateItem'])->whereNumber('item')->name('items.update');
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
