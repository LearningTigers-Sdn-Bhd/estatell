<?php

use App\Http\Controllers\Admin\OwnerSetupLinkController;
use App\Http\Controllers\Admin\PartnerController;
use App\Http\Controllers\Admin\PartnerStatusController;
use App\Http\Controllers\Admin\PartnerTemporaryPasswordController;
use App\Http\Controllers\Admin\PlatformSettingsController;
use App\Http\Controllers\Admin\PropertyController;
use App\Http\Controllers\Admin\PropertyFeatureController;
use App\Http\Controllers\Admin\PropertyStatusController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'can:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::inertia('/', 'admin/dashboard')->name('dashboard');

    Route::get('properties', [PropertyController::class, 'index'])->name('properties.index');
    Route::post('properties', [PropertyController::class, 'store'])->middleware('support.2fa')->name('properties.store');
    Route::get('properties/{property:code}', [PropertyController::class, 'show'])->where(['property' => '[A-Za-z]{2}[0-9]{5,}'])->name('properties.show');
    Route::get('properties/{property:code}/staff', [PropertyController::class, 'staff'])->where(['property' => '[A-Za-z]{2}[0-9]{5,}'])->name('properties.staff');
    Route::get('properties/{property:code}/features', [PropertyController::class, 'features'])->where(['property' => '[A-Za-z]{2}[0-9]{5,}'])->name('properties.features');
    Route::put('properties/{property:code}/features/{feature}', [PropertyFeatureController::class, 'update'])->where(['property' => '[A-Za-z]{2}[0-9]{5,}'])->middleware('support.2fa')->name('properties.features.update');
    Route::post('properties/{property:code}/suspend', [PropertyStatusController::class, 'suspend'])->where(['property' => '[A-Za-z]{2}[0-9]{5,}'])->middleware('support.2fa')->name('properties.suspend');
    Route::post('properties/{property:code}/reactivate', [PropertyStatusController::class, 'reactivate'])->where(['property' => '[A-Za-z]{2}[0-9]{5,}'])->middleware('support.2fa')->name('properties.reactivate');
    Route::post('properties/{property:code}/owner/setup-link', OwnerSetupLinkController::class)->where(['property' => '[A-Za-z]{2}[0-9]{5,}'])->middleware('support.2fa')->name('properties.owner.setup-link');
    Route::get('partners', [PartnerController::class, 'index'])->name('partners.index');
    Route::get('partners/suspended', [PartnerController::class, 'suspended'])->name('partners.suspended');
    Route::post('partners', [PartnerController::class, 'store'])->middleware('support.2fa')->name('partners.store');
    Route::post('partners/temporary-passwords/message', [PartnerTemporaryPasswordController::class, 'message'])->middleware('support.2fa')->name('partners.temporary-passwords.message');
    Route::post('partners/temporary-passwords/export', [PartnerTemporaryPasswordController::class, 'export'])->middleware('support.2fa')->name('partners.temporary-passwords.export');
    Route::post('partners/temporary-passwords/reset', [PartnerTemporaryPasswordController::class, 'reset'])->middleware('support.2fa')->name('partners.temporary-passwords.reset');
    Route::post('partners/{partner}/suspend', [PartnerStatusController::class, 'suspend'])->middleware('support.2fa')->name('partners.suspend');
    Route::post('partners/{partner}/reactivate', [PartnerStatusController::class, 'reactivate'])->middleware('support.2fa')->name('partners.reactivate');
    Route::get('settings', [PlatformSettingsController::class, 'edit'])->name('settings.edit');
    Route::put('settings', [PlatformSettingsController::class, 'update'])->middleware('support.2fa')->name('settings.update');
});
