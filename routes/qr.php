<?php

use App\Http\Controllers\Pos\QrLogoController;
use App\Http\Controllers\Pos\QrRoomController;
use App\Http\Controllers\Pos\QrTableController;
use Illuminate\Support\Facades\Route;

/*
 * The guest's QR ordering pages. No login: the token in the URL is the table or the stay, and a room also needs its PIN and
 * the primary guest's last name. The tokens are 32 letters and digits. Every page and every order is throttled by IP.
 * See docs/34-native-pos/06-kitchen-kds-and-qr.md.
 */
Route::prefix('q')->name('qr.')->group(function () {
    Route::get('logo/{token}', QrLogoController::class)->where('token', '[A-Za-z0-9]{32}')->middleware('throttle:60,1')->name('logo');

    Route::prefix('table/{token}')->where(['token' => '[A-Za-z0-9]{32}'])->name('table.')->group(function () {
        Route::get('/', [QrTableController::class, 'show'])->middleware('throttle:30,1')->name('show');
        Route::post('orders', [QrTableController::class, 'orders'])->middleware('throttle:10,15')->name('orders');
    });

    Route::prefix('room/{token}')->where(['token' => '[A-Za-z0-9]{32}'])->name('room.')->group(function () {
        Route::get('/', [QrRoomController::class, 'show'])->middleware('throttle:30,1')->name('show');
        Route::post('unlock', [QrRoomController::class, 'unlock'])->middleware('throttle:10,15')->name('unlock');
        Route::post('orders', [QrRoomController::class, 'orders'])->middleware('throttle:10,15')->name('orders');
    });
});
