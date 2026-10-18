<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\Boats\BoatDepartureController;
use App\Http\Controllers\Boats\BoatFareController;
use App\Http\Controllers\Catalogues\ChargeItemController;
use App\Http\Controllers\Catalogues\DiscountController;
use App\Http\Controllers\Catalogues\PaymentMethodController;
use App\Http\Controllers\Catalogues\RoomRevenueController;
use App\Http\Controllers\Catalogues\RoomRevenueDayUseController;
use App\Http\Controllers\Catalogues\RoomRevenuePolicyController;
use App\Http\Controllers\Catalogues\TaxController;
use App\Http\Controllers\Meals\MealPeriodController;
use App\Http\Controllers\Onboarding\RecommendedItemDismissalController;
use App\Http\Controllers\Property\AgeGroupController;
use App\Http\Controllers\Property\NotificationSettingController;
use App\Http\Controllers\Property\OtaListSettingsController;
use App\Http\Controllers\Property\PrintSettingsController;
use App\Http\Controllers\Property\PropertyLogoController;
use App\Http\Controllers\Property\PropertySettingsController;
use App\Http\Controllers\Rates\RatePlanController;
use App\Http\Controllers\Registration\RegistrationCardController;
use App\Http\Controllers\Registration\RegistrationTermsController;
use App\Http\Controllers\Reservations\BookingSourceController;
use App\Http\Controllers\Rooms\BedController;
use App\Http\Controllers\Rooms\RoomController;
use App\Http\Controllers\Rooms\RoomGroupController;
use App\Http\Controllers\Rooms\RoomTypeController;
use App\Http\Controllers\Staff\RoleController;
use App\Http\Controllers\Staff\StaffBulkAddController;
use App\Http\Controllers\Staff\StaffController;
use App\Http\Controllers\Staff\StaffInvitationController;
use App\Http\Controllers\Staff\StaffRoleController;
use App\Http\Controllers\Staff\StaffStatusController;
use App\Http\Controllers\Staff\StaffTemporaryPasswordController;
use Illuminate\Support\Facades\Route;

Route::prefix('settings')->name('settings.')->group(function () {
    Route::prefix('notifications')->name('notifications.')->middleware('can:'.PermissionEnum::NotificationManage->value)->group(function () {
        Route::get('/', [NotificationSettingController::class, 'index'])->name('index');
        Route::put('{type}/{channel}', [NotificationSettingController::class, 'update'])->name('update');
    });

    Route::middleware('can:'.PermissionEnum::SettingsManage->value)->group(function () {
        Route::get('profile', [PropertySettingsController::class, 'profile'])->name('profile');
        Route::put('profile', [PropertySettingsController::class, 'updateProfile'])->name('profile.update');
        Route::get('business', [PropertySettingsController::class, 'business'])->name('business');
        Route::put('business', [PropertySettingsController::class, 'updateBusiness'])->name('business.update');
        Route::get('/', [PropertySettingsController::class, 'edit'])->name('edit');
        Route::get('operations', [PropertySettingsController::class, 'operations'])->name('operations');
        Route::get('support', [PropertySettingsController::class, 'support'])->name('support');
        Route::get('age-groups', [PropertySettingsController::class, 'ageGroups'])->name('age-groups');
        Route::get('meal-periods', [PropertySettingsController::class, 'mealPeriods'])->name('meal-periods');
        Route::prefix('registration-card')->name('registration-card.')->group(function () {
            Route::get('/', [RegistrationCardController::class, 'general'])->name('general');
            Route::put('/', [RegistrationCardController::class, 'update'])->name('update');
            Route::get('layout', [RegistrationCardController::class, 'layout'])->name('layout');
            Route::put('layout', [RegistrationCardController::class, 'updateFields'])->name('fields.update');
        });
        Route::prefix('printing')->name('printing.')->controller(PrintSettingsController::class)->group(function () {
            Route::get('/', 'general')->name('general');
            Route::put('/', 'update')->name('update');
            Route::get('header', 'header')->name('header');
            Route::put('header', 'updateHeader')->name('header.update');
            Route::get('documents', 'documents')->name('documents');
            Route::put('documents', 'updateDocuments')->name('documents.update');
            Route::get('sample', 'sample')->name('sample');
        });
        Route::controller(PropertyLogoController::class)->group(function () {
            Route::get('logo', 'show')->name('logo');
            Route::post('logo', 'store')->name('logo.store');
            Route::delete('logo', 'destroy')->name('logo.destroy');
        });
        Route::put('{section}', [PropertySettingsController::class, 'update'])
            ->whereIn('section', ['general', 'operations', 'support'])
            ->name('update');
    });

    Route::prefix('registration-card/terms')->name('registration-card.terms')->middleware('can:'.PermissionEnum::RegistrationCardManageTerms->value)->scopeBindings()->group(function () {
        Route::get('/', [RegistrationTermsController::class, 'index']);
        Route::post('documents', [RegistrationTermsController::class, 'storeDocument'])->name('.documents.store');
        Route::delete('documents/{termsDocument}', [RegistrationTermsController::class, 'destroyDocument'])->name('.documents.destroy');
        Route::post('documents/{termsDocument}/versions', [RegistrationTermsController::class, 'storeVersion'])->name('.versions.store');
        Route::post('documents/{termsDocument}/preset', [RegistrationTermsController::class, 'applyPreset'])->name('.preset.apply');
    });

    Route::prefix('room-types')->name('room-types.')->group(function () {
        Route::get('/', [RoomTypeController::class, 'index'])->middleware('can:'.PermissionEnum::RoomsView->value)->name('index');

        Route::middleware('can:'.PermissionEnum::RoomsView->value)->scopeBindings()->group(function () {
            Route::get('{roomType}', [RoomTypeController::class, 'edit'])->name('edit');
            Route::get('{roomType}/rooms', [RoomTypeController::class, 'editRooms'])->name('rooms.edit');
        });

        Route::middleware('can:'.PermissionEnum::RoomsManage->value)->scopeBindings()->group(function () {
            Route::post('/', [RoomTypeController::class, 'store'])->name('store');
            Route::put('order', [RoomTypeController::class, 'reorder'])->name('reorder');
            Route::put('{roomType}', [RoomTypeController::class, 'update'])->name('update');
            Route::put('{roomType}/rooms', [RoomTypeController::class, 'updateRooms'])->name('rooms.update');
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

    Route::prefix('recommended-items')->name('recommended-items.')->middleware('can:'.PermissionEnum::SettingsManage->value)->group(function () {
        Route::post('dismissals', [RecommendedItemDismissalController::class, 'store'])->name('dismissals.store');
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
        Route::get('{chargeItem}/edit', [ChargeItemController::class, 'edit'])->name('edit');
        Route::put('{chargeItem}', [ChargeItemController::class, 'update'])->name('update');
    });

    Route::middleware(['boats', 'can:'.PermissionEnum::SettingsManage->value])->scopeBindings()->group(function () {
        Route::get('boats', [BoatDepartureController::class, 'redirectToDepartures'])->name('boats');
        Route::prefix('boats/departures')->name('boats.departures.')->group(function () {
            Route::get('/', [BoatDepartureController::class, 'index'])->name('index');
            Route::post('/', [BoatDepartureController::class, 'store'])->name('store');
            Route::put('{boatDeparture}', [BoatDepartureController::class, 'update'])->name('update');
            Route::delete('{boatDeparture}', [BoatDepartureController::class, 'destroy'])->name('destroy');
        });
        Route::prefix('boats/fares')->name('boats.fares.')->group(function () {
            Route::get('/', [BoatFareController::class, 'index'])->name('index');
            Route::post('/', [BoatFareController::class, 'store'])->name('store');
            Route::put('{chargeItem}', [BoatFareController::class, 'update'])->name('update');
        });
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
        Route::get('day-use', [RoomRevenueDayUseController::class, 'show'])->name('day-use.show');
        Route::put('day-use', [RoomRevenueDayUseController::class, 'update'])->name('day-use.update');
        Route::post('day-use/preview', [RoomRevenueDayUseController::class, 'preview'])->name('day-use.preview');
        Route::get('{tab}', [RoomRevenuePolicyController::class, 'show'])->name('policies.show');
        Route::put('{tab}', [RoomRevenuePolicyController::class, 'update'])->name('policies.update');
        Route::post('{tab}/preview', [RoomRevenuePolicyController::class, 'preview'])->name('policies.preview');
    });

    Route::prefix('payment-methods')->name('payment-methods.')->middleware('can:'.PermissionEnum::SettingsManage->value)->scopeBindings()->group(function () {
        Route::get('/', [PaymentMethodController::class, 'index'])->name('index');
        Route::post('/', [PaymentMethodController::class, 'store'])->name('store');
        Route::put('order', [PaymentMethodController::class, 'reorder'])->name('reorder');
        Route::put('{paymentMethod}', [PaymentMethodController::class, 'update'])->name('update');
    });

    Route::prefix('ota-lists')->name('ota-lists.')->middleware('can:'.PermissionEnum::SettingsOtaLists->value)->group(function () {
        Route::get('/', [OtaListSettingsController::class, 'edit'])->name('edit');
        Route::put('/', [OtaListSettingsController::class, 'update'])->name('update');
    });

    Route::prefix('booking-sources')->name('booking-sources.')->middleware('can:'.PermissionEnum::SettingsManage->value)->scopeBindings()->group(function () {
        Route::get('/', [BookingSourceController::class, 'index'])->name('index');
        Route::post('/', [BookingSourceController::class, 'store'])->name('store');
        Route::put('order', [BookingSourceController::class, 'reorder'])->name('reorder');
        Route::put('{bookingSource}', [BookingSourceController::class, 'update'])->name('update');
        Route::delete('{bookingSource}', [BookingSourceController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('discounts')->name('discounts.')->middleware('can:'.PermissionEnum::SettingsManage->value)->scopeBindings()->group(function () {
        Route::get('/', [DiscountController::class, 'index'])->name('index');
        Route::post('/', [DiscountController::class, 'store'])->name('store');
        Route::put('order', [DiscountController::class, 'reorder'])->name('reorder');
        Route::put('{discount}', [DiscountController::class, 'update'])->name('update');
    });

    Route::prefix('rate-plans')->name('rate-plans.')->middleware('can:'.PermissionEnum::SettingsManage->value)->scopeBindings()->group(function () {
        Route::get('/', [RatePlanController::class, 'index'])->name('index');
        Route::post('/', [RatePlanController::class, 'store'])->name('store');
        Route::put('order', [RatePlanController::class, 'reorder'])->name('reorder');
        Route::get('{ratePlan}', [RatePlanController::class, 'edit'])->name('edit');
        Route::put('{ratePlan}', [RatePlanController::class, 'update'])->name('update');
        Route::get('{ratePlan}/room-types', [RatePlanController::class, 'editRoomTypes'])->name('room-types.edit');
        Route::put('{ratePlan}/room-types', [RatePlanController::class, 'updateRoomTypes'])->name('room-types.update');
        Route::get('{ratePlan}/child-prices', [RatePlanController::class, 'editChildPrices'])->name('child-prices.edit');
        Route::put('{ratePlan}/child-prices', [RatePlanController::class, 'updateChildPrices'])->name('child-prices.update');
        Route::get('{ratePlan}/inclusions', [RatePlanController::class, 'editInclusions'])->name('inclusions.edit');
        Route::put('{ratePlan}/inclusions', [RatePlanController::class, 'updateInclusions'])->name('inclusions.update');
        Route::get('{ratePlan}/discounts', [RatePlanController::class, 'editDiscounts'])->name('discounts.edit');
        Route::put('{ratePlan}/discounts', [RatePlanController::class, 'updateDiscounts'])->name('discounts.update');
        Route::get('{ratePlan}/portal', [RatePlanController::class, 'editPortal'])->name('portal.edit');
        Route::put('{ratePlan}/portal', [RatePlanController::class, 'updatePortal'])->name('portal.update');
        Route::post('{ratePlan}/discounts/copy', [RatePlanController::class, 'copyDiscounts'])->name('discounts.copy');
        Route::post('{ratePlan}/discounts/preview', [RatePlanController::class, 'previewDiscounts'])->name('discounts.preview');
        Route::get('{ratePlan}/pricing', [RatePlanController::class, 'editPricing'])->name('pricing.edit');
        Route::put('{ratePlan}/pricing', [RatePlanController::class, 'updatePricing'])->name('pricing.update');
        Route::put('{ratePlan}/pricing/package', [RatePlanController::class, 'updatePackagePricing'])->name('pricing.package.update');
        Route::put('{ratePlan}/pricing/derivation', [RatePlanController::class, 'updateDerivation'])->name('pricing.derivation.update');
        Route::post('{ratePlan}/pricing/derivation/preview', [RatePlanController::class, 'previewDerivation'])->name('pricing.derivation.preview');
        Route::post('{ratePlan}/pricing/extend', [RatePlanController::class, 'extendPricing'])->name('pricing.extend');
        Route::post('{ratePlan}/derived', [RatePlanController::class, 'storeDerived'])->name('derived.store');
        Route::post('{ratePlan}/derived/preview', [RatePlanController::class, 'previewDerived'])->name('derived.preview');
        Route::post('{ratePlan}/archive', [RatePlanController::class, 'archive'])->name('archive');
        Route::post('{ratePlan}/restore', [RatePlanController::class, 'restore'])->name('restore');
    });

    Route::prefix('meal-periods')->name('meal-periods.')->middleware('can:'.PermissionEnum::SettingsManage->value)->group(function () {
        Route::put('/', [MealPeriodController::class, 'update'])->name('update');
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
            Route::post('/', [StaffTemporaryPasswordController::class, 'store'])->name('store');
            Route::get('bulk', [StaffBulkAddController::class, 'create'])->name('bulk.create');
            Route::post('bulk', [StaffBulkAddController::class, 'store'])->name('bulk.store');
            Route::post('import', [StaffBulkAddController::class, 'import'])->name('import');
            Route::get('import-template.{format}', [StaffBulkAddController::class, 'template'])->name('import-template');
            Route::post('temp-passwords/reset', [StaffTemporaryPasswordController::class, 'reset'])->name('temp-passwords.reset');
            Route::post('temp-passwords/message', [StaffTemporaryPasswordController::class, 'message'])->name('temp-passwords.message');
            Route::post('temp-passwords/export', [StaffTemporaryPasswordController::class, 'export'])->name('temp-passwords.export');
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
