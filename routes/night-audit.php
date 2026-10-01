<?php

use App\Http\Controllers\NightAudit\BusinessDateController;
use Illuminate\Support\Facades\Route;

Route::get('business-date', [BusinessDateController::class, 'show'])->name('business-date.show');
