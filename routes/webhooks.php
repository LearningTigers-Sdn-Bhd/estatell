<?php

use App\Http\Controllers\Webhooks\ChannexWebhookController;
use App\Http\Middleware\VerifyChannexSignature;
use Illuminate\Support\Facades\Route;

Route::post('channex', ChannexWebhookController::class)->middleware(VerifyChannexSignature::class)->name('channex');
