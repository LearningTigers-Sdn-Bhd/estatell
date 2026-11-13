<?php

use App\Http\Controllers\Concierge\ConciergeController;
use App\Http\Controllers\Webhooks\ChannexWebhookController;
use App\Http\Controllers\Webhooks\CurlecReturnController;
use App\Http\Controllers\Webhooks\CurlecWebhookController;
use App\Http\Controllers\Webhooks\MillionSendWebhookController;
use App\Http\Middleware\VerifyChannexSignature;
use App\Http\Middleware\VerifyCurlecSignature;
use App\Http\Middleware\VerifyMillionSendSignature;
use Illuminate\Support\Facades\Route;

Route::post('channex', ChannexWebhookController::class)->middleware(VerifyChannexSignature::class)->name('channex');
Route::post('millionsend', MillionSendWebhookController::class)->middleware(VerifyMillionSendSignature::class)->name('millionsend');
Route::post('curlec', CurlecWebhookController::class)->middleware(VerifyCurlecSignature::class)->name('curlec');
Route::match(['get', 'post'], 'curlec/return/{token}', CurlecReturnController::class)->name('curlec.return');
Route::post('curlec/stay/{token}', [ConciergeController::class, 'paymentReturn'])->middleware('concierge')->name('curlec.stay-return');
