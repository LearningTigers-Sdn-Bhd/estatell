<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\AiAssistant\AiChatsController;
use Illuminate\Support\Facades\Route;

/**
 * The AI chats inbox: what the assistant handed over to the front desk. See docs/35-ai-assistant/03-places-and-routes.md#ai-chats-inbox.
 */
Route::prefix('ai-chats')->name('ai-chats.')->middleware(['ai-assistant', 'can:'.PermissionEnum::FrontDeskView->value])->group(function () {
    Route::get('/', [AiChatsController::class, 'index'])->name('index');
    Route::get('all', [AiChatsController::class, 'all'])->name('all');
    Route::post('{thread}/resolve', [AiChatsController::class, 'resolve'])->whereUuid('thread')->name('resolve');
    Route::post('{thread}/close', [AiChatsController::class, 'close'])->whereUuid('thread')->middleware('can:'.PermissionEnum::AiChatsClose->value)->name('close');
});
