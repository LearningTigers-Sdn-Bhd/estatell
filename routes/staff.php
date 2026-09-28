<?php

use App\Http\Controllers\Staff\AcceptInvitationController;
use Illuminate\Support\Facades\Route;

Route::prefix('invitations')->name('invitations.')->middleware('guest')->group(function () {
    Route::get('{token}', [AcceptInvitationController::class, 'show'])->name('show');
    Route::post('{token}', [AcceptInvitationController::class, 'store'])->middleware('throttle:6,1')->name('accept');
});
