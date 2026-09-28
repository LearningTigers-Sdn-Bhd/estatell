<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\Property\NotificationSettingController;
use App\Http\Controllers\Property\PropertySettingsController;
use App\Http\Controllers\Rooms\RoomTypeController;
use App\Http\Controllers\Staff\RoleController;
use App\Http\Controllers\Staff\StaffController;
use App\Http\Controllers\Staff\StaffInvitationController;
use App\Http\Controllers\Staff\StaffRoleController;
use App\Http\Controllers\Staff\StaffStatusController;
use Illuminate\Support\Facades\Route;

Route::prefix('settings')->name('settings.')->group(function () {
    Route::prefix('notifications')->name('notifications.')->middleware('can:'.PermissionEnum::NotificationManage->value)->group(function () {
        Route::get('/', [NotificationSettingController::class, 'index'])->name('index');
        Route::put('{type}/{channel}', [NotificationSettingController::class, 'update'])->name('update');
    });

    Route::middleware('can:'.PermissionEnum::SettingsManage->value)->group(function () {
        Route::get('/', [PropertySettingsController::class, 'edit'])->name('edit');
        Route::get('operations', [PropertySettingsController::class, 'operations'])->name('operations');
        Route::get('registration', [PropertySettingsController::class, 'registration'])->name('registration');
        Route::get('support', [PropertySettingsController::class, 'support'])->name('support');
        Route::put('{section}', [PropertySettingsController::class, 'update'])
            ->whereIn('section', ['general', 'operations', 'registration', 'support'])
            ->name('update');
    });

    Route::prefix('room-types')->name('room-types.')->group(function () {
        Route::get('/', [RoomTypeController::class, 'index'])->middleware('can:'.PermissionEnum::RoomsView->value)->name('index');

        Route::middleware('can:'.PermissionEnum::RoomsManage->value)->scopeBindings()->group(function () {
            Route::post('/', [RoomTypeController::class, 'store'])->name('store');
            Route::put('order', [RoomTypeController::class, 'reorder'])->name('reorder');
            Route::put('{roomType}', [RoomTypeController::class, 'update'])->name('update');
            Route::post('{roomType}/archive', [RoomTypeController::class, 'archive'])->name('archive');
            Route::post('{roomType}/restore', [RoomTypeController::class, 'restore'])->name('restore');
        });
    });

    Route::prefix('staff')->name('staff.')->group(function () {
        Route::middleware('can:'.PermissionEnum::StaffView->value)->group(function () {
            Route::get('/', [StaffController::class, 'index'])->name('index');
            Route::get('invited', [StaffController::class, 'invited'])->name('invited');
            Route::get('deactivated', [StaffController::class, 'deactivated'])->name('deactivated');
        });

        Route::middleware('can:'.PermissionEnum::StaffManage->value)->scopeBindings()->group(function () {
            Route::post('invitations', [StaffInvitationController::class, 'store'])->name('invitations.store');
            Route::post('invitations/{staffInvitation}/resend', [StaffInvitationController::class, 'resend'])->name('invitations.resend');
            Route::delete('invitations/{staffInvitation}', [StaffInvitationController::class, 'destroy'])->name('invitations.destroy');
            Route::post('{user}/deactivate', [StaffStatusController::class, 'deactivate'])->name('deactivate');
            Route::post('{user}/reactivate', [StaffStatusController::class, 'reactivate'])->name('reactivate');
            Route::put('{user}/role', [StaffRoleController::class, 'update'])->name('role.update');
        });
    });

    Route::prefix('roles')->name('roles.')->middleware('can:'.PermissionEnum::RolesManage->value)->group(function () {
        Route::get('/', [RoleController::class, 'index'])->name('index');
        Route::post('/', [RoleController::class, 'store'])->name('store');
        Route::put('/', [RoleController::class, 'update'])->name('update');
    });
});
