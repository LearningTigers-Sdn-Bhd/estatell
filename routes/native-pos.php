<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\Pos\PosPinController;
use App\Http\Controllers\Pos\PosStaffPinController;
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
    });

    Route::prefix('settings/pos')->name('settings.pos.')->middleware('can:'.PermissionEnum::PosManage->value)->group(function () {
        Route::post('devices', [PosTerminalDeviceController::class, 'store'])->name('devices.store');
        Route::post('devices/{device}/revoke', [PosTerminalDeviceController::class, 'revoke'])->whereNumber('device')->name('devices.revoke');
        Route::post('staff-pins/{user}/revoke', [PosStaffPinController::class, 'revoke'])->whereNumber('user')->name('staff-pins.revoke');
    });
});
