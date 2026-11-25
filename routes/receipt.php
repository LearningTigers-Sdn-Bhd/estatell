<?php

use App\Http\Controllers\Pos\PosReceiptClaimController;
use Illuminate\Support\Facades\Route;

/*
 * The e-invoice claim page behind the QR on a walk-in receipt. No login: the slip's own token opens it, the exact total and
 * date prove the guest holds it, and the order counts its tries. The page is limited to 10 views a minute per IP and the
 * two writes to 5 an hour per IP. See docs/34-native-pos/05-shifts-audit-and-einvoice.md.
 */
Route::prefix('receipt/{token}')->where(['token' => '[A-Za-z0-9]{32}'])->name('pos.receipt-claim.')->group(function () {
    Route::get('/', [PosReceiptClaimController::class, 'show'])->middleware('throttle:10,1')->name('show');
    Route::post('confirm', [PosReceiptClaimController::class, 'confirm'])->middleware('throttle:5,60')->name('confirm');
    Route::post('claim', [PosReceiptClaimController::class, 'claim'])->middleware('throttle:5,60')->name('claim');
});
