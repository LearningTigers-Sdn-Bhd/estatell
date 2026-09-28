<?php

use App\Http\Controllers\Admin\OwnerSetupLinkController;
use App\Http\Controllers\Admin\PlatformSettingsController;
use App\Http\Controllers\Admin\PropertyController;
use App\Http\Controllers\Admin\PropertyFeatureController;
use App\Http\Controllers\Admin\PropertyStatusController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'can:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::inertia('/', 'admin/dashboard')->name('dashboard');

    Route::get('properties', [PropertyController::class, 'index'])->name('properties.index');
    Route::post('properties', [PropertyController::class, 'store'])->middleware('admin.2fa')->name('properties.store');
    Route::get('properties/{property}', [PropertyController::class, 'show'])->whereNumber('property')->name('properties.show');
    Route::get('properties/{property}/staff', [PropertyController::class, 'staff'])->whereNumber('property')->name('properties.staff');
    Route::get('properties/{property}/features', [PropertyController::class, 'features'])->whereNumber('property')->name('properties.features');
    Route::put('properties/{property}/features/{feature}', [PropertyFeatureController::class, 'update'])->whereNumber('property')->middleware('admin.2fa')->name('properties.features.update');
    Route::post('properties/{property}/suspend', [PropertyStatusController::class, 'suspend'])->whereNumber('property')->middleware('admin.2fa')->name('properties.suspend');
    Route::post('properties/{property}/reactivate', [PropertyStatusController::class, 'reactivate'])->whereNumber('property')->middleware('admin.2fa')->name('properties.reactivate');
    Route::post('properties/{property}/owner/setup-link', OwnerSetupLinkController::class)->whereNumber('property')->middleware('admin.2fa')->name('properties.owner.setup-link');
    Route::get('settings', [PlatformSettingsController::class, 'edit'])->name('settings.edit');
    Route::put('settings', [PlatformSettingsController::class, 'update'])->middleware('admin.2fa')->name('settings.update');
});
