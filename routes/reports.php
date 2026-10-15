<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\Reports\ActivityLogController;
use App\Http\Controllers\Reports\ArrivalsReportController;
use App\Http\Controllers\Reports\BoatManifestReportController;
use App\Http\Controllers\Reports\DeparturesReportController;
use App\Http\Controllers\Reports\DepositLiabilityReportController;
use App\Http\Controllers\Reports\FolioLedgerReportController;
use App\Http\Controllers\Reports\InHouseReportController;
use App\Http\Controllers\Reports\MealCountsReportController;
use App\Http\Controllers\Reports\NightAuditHistoryReportController;
use App\Http\Controllers\Reports\NightAuditPacketController;
use App\Http\Controllers\Reports\NonNationalGuestsReportController;
use App\Http\Controllers\Reports\OccupancyReportController;
use App\Http\Controllers\Reports\OutstandingBalanceReportController;
use App\Http\Controllers\Reports\PaymentsReportController;
use App\Http\Controllers\Reports\PoliceReportController;
use App\Http\Controllers\Reports\ReportExportController;
use App\Http\Controllers\Reports\ReportLayoutController;
use App\Http\Controllers\Reports\RevenueReportController;
use App\Http\Controllers\Reports\TaxReportController;
use App\Http\Middleware\EnsureReportIsAvailable;
use Illuminate\Support\Facades\Route;

Route::prefix('reports')->name('reports.')->middleware(['can:'.PermissionEnum::ReportsView->value, EnsureReportIsAvailable::class])->group(function () {
    Route::get('activity-log', ActivityLogController::class)->name('activity-log');
    Route::get('arrivals', ArrivalsReportController::class)->name('arrivals');
    Route::get('departures', DeparturesReportController::class)->name('departures');
    Route::get('in-house', InHouseReportController::class)->name('in-house');
    Route::get('occupancy', OccupancyReportController::class)->name('occupancy');
    Route::get('revenue', RevenueReportController::class)->name('revenue');
    Route::get('payments', PaymentsReportController::class)->name('payments');
    Route::get('folio-ledger', FolioLedgerReportController::class)->name('folio-ledger');
    Route::get('tax', TaxReportController::class)->name('tax');
    Route::get('meal-counts', MealCountsReportController::class)->name('meal-counts');
    Route::get('outstanding-balance', OutstandingBalanceReportController::class)->name('outstanding-balance');
    Route::get('deposit-liability', DepositLiabilityReportController::class)->name('deposit-liability');
    Route::get('non-national-guests', NonNationalGuestsReportController::class)->name('non-national-guests');
    Route::get('police-report', PoliceReportController::class)->name('police-report');
    Route::get('boat-manifest', BoatManifestReportController::class)->name('boat-manifest');
    Route::get('night-audit-history', NightAuditHistoryReportController::class)->name('night-audit-history');
    Route::get('night-audit-history/{date}/packet', NightAuditPacketController::class)->name('night-audit-packet');

    Route::post('{report}/pdf', [ReportExportController::class, 'pdf'])->name('pdf');
    Route::post('{report}/spreadsheet', [ReportExportController::class, 'spreadsheet'])->name('spreadsheet');

    Route::prefix('{report}/layout')->name('layout.')->middleware('can:'.PermissionEnum::SettingsManage->value)->group(function () {
        Route::put('/', [ReportLayoutController::class, 'update'])->name('update');
        Route::delete('/', [ReportLayoutController::class, 'destroy'])->name('destroy');
        Route::post('preview', [ReportLayoutController::class, 'preview'])->name('preview');
    });
});
