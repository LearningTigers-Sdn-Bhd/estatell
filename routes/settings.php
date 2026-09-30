<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\Catalogues\ChargeItemController;
use App\Http\Controllers\Catalogues\DiscountController;
use App\Http\Controllers\Catalogues\PaymentMethodController;
use App\Http\Controllers\Catalogues\RoomRevenueController;
use App\Http\Controllers\Catalogues\TaxController;
use App\Http\Controllers\Meals\MealPeriodController;
use App\Http\Controllers\Property\AgeGroupController;
use App\Http\Controllers\Property\NotificationSettingController;
use App\Http\Controllers\Property\PropertySettingsController;
use App\Http\Controllers\Rooms\BedController;
use App\Http\Controllers\Rooms\RoomController;
use App\Http\Controllers\Rooms\RoomGroupController;
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
        Route::get('age-groups', [PropertySettingsController::class, 'ageGroups'])->name('age-groups');
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

    Route::prefix('rooms')->name('rooms.')->group(function () {
        Route::get('/', [RoomController::class, 'index'])->middleware('can:'.PermissionEnum::RoomsView->value)->name('index');

        Route::middleware('can:'.PermissionEnum::RoomsManage->value)->scopeBindings()->group(function () {
            Route::post('/', [RoomController::class, 'store'])->name('store');
            Route::post('generate/preview', [RoomController::class, 'preview'])->name('generate.preview');
            Route::post('generate', [RoomController::class, 'generate'])->name('generate');
            Route::post('beds/generate', [BedController::class, 'generate'])->name('beds.generate');
            Route::put('{room}', [RoomController::class, 'update'])->name('update');
            Route::post('{room}/archive', [RoomController::class, 'archive'])->name('archive');
            Route::post('{room}/restore', [RoomController::class, 'restore'])->name('restore');
            Route::post('{room}/beds', [BedController::class, 'store'])->name('beds.store');
            Route::put('{room}/beds/{bed}', [BedController::class, 'update'])->name('beds.update');
            Route::post('{room}/beds/{bed}/archive', [BedController::class, 'archive'])->name('beds.archive');
            Route::post('{room}/beds/{bed}/restore', [BedController::class, 'restore'])->name('beds.restore');
        });
    });

    Route::prefix('room-groups')->name('room-groups.')->middleware('can:'.PermissionEnum::RoomsManage->value)->scopeBindings()->group(function () {
        Route::post('/', [RoomGroupController::class, 'store'])->name('store');
        Route::put('order', [RoomGroupController::class, 'reorder'])->name('reorder');
        Route::put('{roomGroup}', [RoomGroupController::class, 'update'])->name('update');
        Route::delete('{roomGroup}', [RoomGroupController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('charge-items')->name('charge-items.')->middleware('can:'.PermissionEnum::SettingsManage->value)->scopeBindings()->group(function () {
        Route::get('/', [ChargeItemController::class, 'index'])->name('index');
        Route::post('/', [ChargeItemController::class, 'store'])->name('store');
        Route::put('order', [ChargeItemController::class, 'reorder'])->name('reorder');
        Route::put('{chargeItem}', [ChargeItemController::class, 'update'])->name('update');
    });

    Route::prefix('taxes')->name('taxes.')->middleware('can:'.PermissionEnum::SettingsManage->value)->scopeBindings()->group(function () {
        Route::get('/', [TaxController::class, 'index'])->name('index');
        Route::post('/', [TaxController::class, 'store'])->name('store');
        Route::put('{tax}', [TaxController::class, 'update'])->name('update');
    });

    Route::prefix('room-revenue')->name('room-revenue.')->middleware('can:'.PermissionEnum::SettingsManage->value)->group(function () {
        Route::get('/', [RoomRevenueController::class, 'index'])->name('index');
        Route::put('/', [RoomRevenueController::class, 'update'])->name('update');
        Route::post('preview', [RoomRevenueController::class, 'preview'])->name('preview');
    });

    Route::prefix('payment-methods')->name('payment-methods.')->middleware('can:'.PermissionEnum::SettingsManage->value)->scopeBindings()->group(function () {
        Route::get('/', [PaymentMethodController::class, 'index'])->name('index');
        Route::post('/', [PaymentMethodController::class, 'store'])->name('store');
        Route::put('order', [PaymentMethodController::class, 'reorder'])->name('reorder');
        Route::put('{paymentMethod}', [PaymentMethodController::class, 'update'])->name('update');
    });

    Route::prefix('discounts')->name('discounts.')->middleware('can:'.PermissionEnum::SettingsManage->value)->scopeBindings()->group(function () {
        Route::get('/', [DiscountController::class, 'index'])->name('index');
        Route::post('/', [DiscountController::class, 'store'])->name('store');
        Route::put('order', [DiscountController::class, 'reorder'])->name('reorder');
        Route::put('{discount}', [DiscountController::class, 'update'])->name('update');
    });

    Route::prefix('meal-periods')->name('meal-periods.')->middleware('can:'.PermissionEnum::SettingsManage->value)->scopeBindings()->group(function () {
        Route::get('/', [MealPeriodController::class, 'index'])->name('index');
        Route::post('/', [MealPeriodController::class, 'store'])->name('store');
        Route::put('{mealPeriod}', [MealPeriodController::class, 'update'])->name('update');
    });

    Route::prefix('age-groups')->name('age-groups.')->middleware('can:'.PermissionEnum::SettingsManage->value)->scopeBindings()->group(function () {
        Route::put('/', [AgeGroupController::class, 'update'])->name('update');
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
