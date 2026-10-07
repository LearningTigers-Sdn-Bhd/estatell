<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\NightAudit\BusinessDateController;
use App\Http\Controllers\NightAudit\NightAuditController;
use Illuminate\Support\Facades\Route;

Route::get('business-date', [BusinessDateController::class, 'show'])->name('business-date.show');

Route::prefix('night-audit')->name('night-audit.')->scopeBindings()->middleware(['live', 'can:'.PermissionEnum::NightAuditRun->value])->group(function () {
    Route::get('/', [NightAuditController::class, 'show'])->name('show');
    Route::post('run', [NightAuditController::class, 'run'])->name('run');
    Route::get('reservations/{reservation}', [NightAuditController::class, 'reservation'])->middleware('can:'.PermissionEnum::ReservationsView->value)->name('reservation');
    Route::post('reservations/{reservation}/fix/{fix}', [NightAuditController::class, 'fix'])->middleware('can:'.PermissionEnum::ReservationsManage->value)->name('fix');
});
