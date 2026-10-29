<?php

use App\Http\Controllers\DoorLocks\KeyportDeviceController;
use App\Http\Controllers\DoorLocks\KeyportJobController;
use Illuminate\Support\Facades\Route;

// The Keyport agent API: `/api/keyport/v1`. Contract: ../estatell-keyport/docs/ESTATELL_CONTRACT.md.
Route::post('pair', [KeyportDeviceController::class, 'pair'])->middleware('throttle:keyport-pair')->name('pair');

Route::middleware('keyport.device')->group(function (): void {
    Route::post('unpair', [KeyportDeviceController::class, 'unpair'])->name('unpair');
    Route::post('jobs/claim', [KeyportJobController::class, 'claim'])->name('jobs.claim');
    Route::post('jobs/{job}/result', [KeyportJobController::class, 'result'])->name('jobs.result');
});
