<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\NightAudit\BusinessDateController;
use App\Http\Controllers\NightAudit\NightAuditController;
use Illuminate\Support\Facades\Route;

Route::get('business-date', [BusinessDateController::class, 'show'])->name('business-date.show');

Route::prefix('night-audit')->name('night-audit.')->scopeBindings()->middleware('can:'.PermissionEnum::NightAuditRun->value)->group(function () {
    Route::get('/', [NightAuditController::class, 'show'])->name('show');
    Route::post('run', [NightAuditController::class, 'run'])->name('run');
    Route::get('reservations/{reservation}', [NightAuditController::class, 'reservation'])->middleware('can:'.PermissionEnum::ReservationsView->value)->name('reservation');
    Route::post('reservations/{reservation}/fix/{fix}', [NightAuditController::class, 'fix'])->middleware('can:'.PermissionEnum::ReservationsManage->value)->name('fix');
    Route::post('exceptions/{nightAuditException}/retry', [NightAuditController::class, 'retry'])->middleware('can:'.PermissionEnum::ReservationsManage->value)->name('exceptions.retry');
    Route::post('exceptions/{nightAuditException}/fix-price/preview', [NightAuditController::class, 'fixPricePreview'])->middleware('can:'.PermissionEnum::ReservationsManage->value)->name('exceptions.fix-price.preview');
    Route::post('exceptions/{nightAuditException}/fix-price', [NightAuditController::class, 'fixPrice'])->middleware('can:'.PermissionEnum::ReservationsManage->value)->name('exceptions.fix-price');
    Route::post('exceptions/{nightAuditException}/dismiss', [NightAuditController::class, 'dismiss'])->name('exceptions.dismiss');
});
