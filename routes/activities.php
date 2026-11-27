<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\Facilities\ActivityRunSheetController;
use Illuminate\Support\Facades\Route;

/*
 * Front desk › Activities: the run sheet of the activity sessions of a date, with a guide and boat per session.
 * See docs/33-facilities/06-guides-and-boats.md#routes.
 */
Route::prefix('activities')->name('activities.')->middleware('facilities')->scopeBindings()->group(function () {
    Route::get('/', [ActivityRunSheetController::class, 'index'])->middleware('can:'.PermissionEnum::FrontDeskView->value)->name('index');
    Route::put('{facility}/crew', [ActivityRunSheetController::class, 'assign'])->whereNumber('facility')->middleware('can:'.PermissionEnum::FacilitiesBook->value)->name('assign');
});
