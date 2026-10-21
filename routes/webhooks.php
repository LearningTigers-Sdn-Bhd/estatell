<?php

use App\Http\Controllers\Webhooks\ChannexWebhookController;
use App\Http\Controllers\Webhooks\MillionSendWebhookController;
use App\Http\Middleware\VerifyChannexSignature;
use App\Http\Middleware\VerifyMillionSendSignature;
use Illuminate\Support\Facades\Route;

Route::post('channex', ChannexWebhookController::class)->middleware(VerifyChannexSignature::class)->name('channex');
Route::post('millionsend', MillionSendWebhookController::class)->middleware(VerifyMillionSendSignature::class)->name('millionsend');
