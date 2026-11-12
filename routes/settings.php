<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\Accounting\AccountingSettingsController;
use App\Http\Controllers\ApiAccess\KioskController;
use App\Http\Controllers\Boats\BoatController;
use App\Http\Controllers\Boats\BoatCrewController;
use App\Http\Controllers\Boats\BoatDepartureController;
use App\Http\Controllers\Boats\BoatFareController;
use App\Http\Controllers\BookingEngine\BookingApiController;
use App\Http\Controllers\BookingEngine\BookingWebsiteController;
use App\Http\Controllers\BookingEngine\NearbyPlaceController;
use App\Http\Controllers\Catalogues\BankAccountController;
use App\Http\Controllers\Catalogues\ChargeItemController;
use App\Http\Controllers\Catalogues\DiscountController;
use App\Http\Controllers\Catalogues\PaymentMethodController;
use App\Http\Controllers\Catalogues\RoomRevenueController;
use App\Http\Controllers\Catalogues\RoomRevenueDayUseController;
use App\Http\Controllers\Catalogues\RoomRevenuePolicyController;
use App\Http\Controllers\Catalogues\TaxController;
use App\Http\Controllers\CityLedger\AgentPaymentTermsController;
use App\Http\Controllers\Concierge\ConciergeSettingsController;
use App\Http\Controllers\DoorLocks\DoorLockSettingsController;
use App\Http\Controllers\DoorLocks\KeyportSettingsController;
use App\Http\Controllers\Meals\MealPeriodController;
use App\Http\Controllers\Onboarding\RecommendedItemDismissalController;
use App\Http\Controllers\Property\AgeGroupController;
use App\Http\Controllers\Property\EInvoiceSettingsController;
use App\Http\Controllers\Property\GuestEmailController;
use App\Http\Controllers\Property\NotificationSettingController;
use App\Http\Controllers\Property\PrintSettingsController;
use App\Http\Controllers\Property\PropertyLogoController;
use App\Http\Controllers\Property\PropertyPhotoController;
use App\Http\Controllers\Property\PropertySettingsController;
use App\Http\Controllers\Rates\RatePlanCompanyController;
use App\Http\Controllers\Rates\RatePlanController;
use App\Http\Controllers\Registration\RegistrationCardController;
use App\Http\Controllers\Registration\RegistrationTermsController;
use App\Http\Controllers\Reservations\BookingSourceController;
use App\Http\Controllers\Rooms\BedController;
use App\Http\Controllers\Rooms\RoomController;
use App\Http\Controllers\Rooms\RoomGroupController;
use App\Http\Controllers\Rooms\RoomTypeAmenityController;
use App\Http\Controllers\Rooms\RoomTypeController;
use App\Http\Controllers\Rooms\RoomTypePhotoController;
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
        Route::get('photos', [PropertySettingsController::class, 'photos'])->name('photos');
        Route::controller(PropertyPhotoController::class)->group(function () {
            Route::post('photos', 'store')->name('photos.store');
            Route::put('photos/order', 'reorder')->name('photos.reorder');
            Route::delete('photos/{photo}', 'destroy')->whereNumber('photo')->name('photos.destroy');
        });
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
            Route::get('{roomType}/photos', [RoomTypePhotoController::class, 'edit'])->name('photos.edit');
            Route::get('{roomType}/amenities', [RoomTypeAmenityController::class, 'edit'])->name('amenities.edit');
        });

        Route::middleware('can:'.PermissionEnum::RoomsManage->value)->scopeBindings()->group(function () {
            Route::post('/', [RoomTypeController::class, 'store'])->name('store');
            Route::put('order', [RoomTypeController::class, 'reorder'])->name('reorder');
            Route::put('{roomType}', [RoomTypeController::class, 'update'])->name('update');
            Route::put('{roomType}/rooms', [RoomTypeController::class, 'updateRooms'])->name('rooms.update');
            Route::post('{roomType}/photos', [RoomTypePhotoController::class, 'store'])->name('photos.store');
            Route::put('{roomType}/photos/order', [RoomTypePhotoController::class, 'reorder'])->name('photos.reorder');
            Route::delete('{roomType}/photos/{photo}', [RoomTypePhotoController::class, 'destroy'])->whereNumber('photo')->name('photos.destroy');
            Route::put('{roomType}/amenities', [RoomTypeAmenityController::class, 'update'])->name('amenities.update');
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
            Route::post('change-type', [RoomController::class, 'changeType'])->name('change-type');
            Route::post('archive-many', [RoomController::class, 'archiveMany'])->name('archive-many');
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
        Route::prefix('boats/boats')->name('boats.boats.')->group(function () {
            Route::get('/', [BoatController::class, 'index'])->name('index');
            Route::post('/', [BoatController::class, 'store'])->name('store');
            Route::put('{boat}', [BoatController::class, 'update'])->name('update');
        });
        Route::prefix('boats/crew')->name('boats.crew.')->group(function () {
            Route::get('/', [BoatCrewController::class, 'index'])->name('index');
            Route::post('/', [BoatCrewController::class, 'store'])->name('store');
            Route::put('{crewMember}', [BoatCrewController::class, 'update'])->name('update');
        });
        Route::put('boats/charter', [BoatFareController::class, 'charter'])->name('boats.charter');
        Route::prefix('boats/fares')->name('boats.fares.')->group(function () {
            Route::get('/', [BoatFareController::class, 'index'])->name('index');
            Route::post('/', [BoatFareController::class, 'store'])->name('store');
            Route::put('{chargeItem}', [BoatFareController::class, 'update'])->name('update');
        });
    });

    Route::middleware('can:'.PermissionEnum::SettingsManage->value)->group(function () {
        Route::get('agent-payments', [AgentPaymentTermsController::class, 'edit'])->name('agent-payments.edit');
        Route::put('agent-payments', [AgentPaymentTermsController::class, 'update'])->name('agent-payments.update');
    });

    Route::prefix('bank-accounts')->name('bank-accounts.')->middleware('can:'.PermissionEnum::SettingsManage->value)->scopeBindings()->group(function () {
        Route::get('/', [BankAccountController::class, 'index'])->name('index');
        Route::post('/', [BankAccountController::class, 'store'])->name('store');
        Route::put('order', [BankAccountController::class, 'reorder'])->name('reorder');
        Route::put('{bankAccount}', [BankAccountController::class, 'update'])->name('update');
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

    Route::prefix('door-locks')->name('door-locks.')->middleware(['door-locks', 'can:'.PermissionEnum::SettingsManage->value])->scopeBindings()->group(function () {
        Route::get('/', [DoorLockSettingsController::class, 'edit'])->name('edit');
        Route::put('/', [DoorLockSettingsController::class, 'update'])->name('update')->middleware('door-locks:ttlock');
        Route::post('test', [DoorLockSettingsController::class, 'test'])->name('test')->middleware('door-locks:ttlock');
        Route::post('sync', [DoorLockSettingsController::class, 'sync'])->name('sync')->middleware('door-locks:ttlock');
        Route::put('locks/{doorLock}/link', [DoorLockSettingsController::class, 'link'])->name('link')->middleware('door-locks:ttlock');
        Route::post('locks/{doorLock}/unlock', [DoorLockSettingsController::class, 'unlock'])->name('unlock')->middleware('door-locks:ttlock');
        Route::post('locks/{doorLock}/clock', [DoorLockSettingsController::class, 'clock'])->name('clock')->middleware('door-locks:ttlock');
        Route::post('locks/{doorLock}/health', [DoorLockSettingsController::class, 'health'])->name('health')->middleware('door-locks:ttlock');
        Route::post('passcodes', [DoorLockSettingsController::class, 'storePasscode'])->name('passcodes.store')->middleware('door-locks:ttlock');
        Route::post('passcodes/{doorPasscode}/revoke', [DoorLockSettingsController::class, 'revoke'])->name('passcodes.revoke')->middleware('door-locks:ttlock');
        Route::post('passcodes/{doorPasscode}/retry', [DoorLockSettingsController::class, 'retry'])->name('passcodes.retry')->middleware('door-locks:ttlock');
    });

    Route::prefix('booking-website')->name('booking-website.')->middleware('booking-engine')->scopeBindings()->group(function () {
        Route::middleware('can:'.PermissionEnum::BookingWebsiteManage->value)->group(function () {
            Route::get('/', [BookingWebsiteController::class, 'general'])->name('general');
            Route::put('/', [BookingWebsiteController::class, 'updateGeneral'])->name('general.update');
            Route::get('design', [BookingWebsiteController::class, 'design'])->name('design');
            Route::put('design', [BookingWebsiteController::class, 'updateDesign'])->name('design.update');
            Route::post('design/images/{slot}', [BookingWebsiteController::class, 'uploadImage'])->where('slot', '[a-z0-9]+(?:-[a-z0-9]+)*')->name('design.images.store');
            Route::post('design/images/{slot}/from-album', [BookingWebsiteController::class, 'copyImage'])->where('slot', '[a-z0-9]+(?:-[a-z0-9]+)*')->name('design.images.copy');
            Route::delete('design/images/{slot}', [BookingWebsiteController::class, 'removeImage'])->where('slot', '[a-z0-9]+(?:-[a-z0-9]+)*')->name('design.images.destroy');
            Route::get('seo', [BookingWebsiteController::class, 'seo'])->name('seo');
            Route::put('seo', [BookingWebsiteController::class, 'updateSeo'])->name('seo.update');
            Route::get('amenities', [BookingWebsiteController::class, 'amenities'])->name('amenities');
            Route::put('amenities', [BookingWebsiteController::class, 'updateAmenities'])->name('amenities.update');
            Route::get('rates', [BookingWebsiteController::class, 'rates'])->name('rates');
            Route::put('rates', [BookingWebsiteController::class, 'updateRates'])->name('rates.update');
            Route::get('nearby-places', [NearbyPlaceController::class, 'index'])->name('nearby-places');
            Route::post('nearby-places', [NearbyPlaceController::class, 'store'])->name('nearby-places.store');
            Route::put('nearby-places/order', [NearbyPlaceController::class, 'reorder'])->name('nearby-places.reorder');
            Route::post('nearby-places/{nearbyPlace}/suggested', [NearbyPlaceController::class, 'storeSuggested'])->withoutScopedBindings()->whereNumber('nearbyPlace')->name('nearby-places.suggested');
            Route::put('nearby-places/{nearbyPlace}', [NearbyPlaceController::class, 'update'])->whereNumber('nearbyPlace')->name('nearby-places.update');
            Route::put('nearby-places/{nearbyPlace}/active', [NearbyPlaceController::class, 'active'])->whereNumber('nearbyPlace')->name('nearby-places.active.update');
            Route::delete('nearby-places/{nearbyPlace}', [NearbyPlaceController::class, 'destroy'])->whereNumber('nearbyPlace')->name('nearby-places.destroy');
        });
        Route::middleware('can:'.PermissionEnum::BookingWebsitePublish->value)->group(function () {
            Route::post('publish', [BookingWebsiteController::class, 'publish'])->name('publish');
            Route::post('unpublish', [BookingWebsiteController::class, 'unpublish'])->name('unpublish');
        });
        Route::middleware('can:'.PermissionEnum::BookingWebsiteApi->value)->group(function () {
            Route::get('api', [BookingApiController::class, 'show'])->name('api');
            Route::post('api/token', [BookingApiController::class, 'issueToken'])->name('api.token.store');
            Route::delete('api/token', [BookingApiController::class, 'revokeToken'])->name('api.token.destroy');
            Route::post('api/return-addresses', [BookingApiController::class, 'storeReturnAddress'])->name('api.return-addresses.store');
            Route::delete('api/return-addresses/{bookingReturnAddress}', [BookingApiController::class, 'destroyReturnAddress'])->whereNumber('bookingReturnAddress')->name('api.return-addresses.destroy');
        });
    });

    Route::prefix('keyport')->name('keyport.')->middleware(['door-locks:elock', 'can:'.PermissionEnum::SettingsManage->value])->scopeBindings()->group(function () {
        Route::get('/', [KeyportSettingsController::class, 'index'])->name('index');
        Route::post('codes', [KeyportSettingsController::class, 'storeCode'])->name('codes.store');
        Route::post('devices/{keyportDevice}/revoke', [KeyportSettingsController::class, 'revokeDevice'])->name('devices.revoke');
        Route::delete('devices/{keyportDevice}', [KeyportSettingsController::class, 'destroyDevice'])->name('devices.destroy');
        Route::post('locks', [KeyportSettingsController::class, 'storeLock'])->name('locks.store');
        Route::put('locks/{doorLock}', [KeyportSettingsController::class, 'updateLock'])->name('locks.update');
        Route::delete('locks/{doorLock}', [KeyportSettingsController::class, 'destroyLock'])->name('locks.destroy');
        Route::post('jobs/{keyportJob}/cancel', [KeyportSettingsController::class, 'cancelJob'])->name('jobs.cancel');
    });

    Route::prefix('accounting')->name('accounting.')->middleware(['accounting', 'can:'.PermissionEnum::SettingsManage->value])->scopeBindings()->group(function () {
        Route::get('/', [AccountingSettingsController::class, 'edit'])->name('edit');
        Route::get('accounts', [AccountingSettingsController::class, 'accounts'])->name('accounts');
        Route::get('history', [AccountingSettingsController::class, 'history'])->name('history');
        Route::put('/', [AccountingSettingsController::class, 'update'])->name('update');
        Route::put('mappings', [AccountingSettingsController::class, 'mappings'])->name('mappings');
        Route::post('test', [AccountingSettingsController::class, 'test'])->name('test');
        Route::post('billing/sync', [AccountingSettingsController::class, 'syncBilling'])->name('billing.sync');
        Route::post('syncs/{accountingSync}/retry', [AccountingSettingsController::class, 'retry'])->name('retry');
    });

    Route::prefix('emails')->name('emails.')->middleware('can:'.PermissionEnum::SettingsManage->value)->group(function () {
        Route::get('/', [GuestEmailController::class, 'edit'])->name('edit');
        Route::put('{email}', [GuestEmailController::class, 'update'])->name('update');
        Route::delete('{email}', [GuestEmailController::class, 'reset'])->name('reset');
    });

    Route::prefix('e-invoice')->name('e-invoice.')->middleware(['einvoicing', 'can:'.PermissionEnum::SettingsManage->value])->group(function () {
        Route::get('/', [EInvoiceSettingsController::class, 'edit'])->name('edit');
        Route::put('/', [EInvoiceSettingsController::class, 'update'])->name('update');
        Route::post('test', [EInvoiceSettingsController::class, 'test'])->name('test');
    });

    Route::prefix('kiosks')->name('kiosks.')->middleware(['kiosks', 'can:'.PermissionEnum::SettingsManage->value])->scopeBindings()->group(function () {
        Route::get('/', [KioskController::class, 'index'])->name('index');
        Route::post('/', [KioskController::class, 'store'])->name('store');
        Route::put('settings', [KioskController::class, 'update'])->name('update');
        Route::post('{kiosk}/revoke', [KioskController::class, 'revoke'])->name('revoke');
    });

    Route::prefix('concierge')->name('concierge.')->middleware(['concierge', 'can:'.PermissionEnum::SettingsManage->value])->scopeBindings()->group(function () {
        Route::get('/', [ConciergeSettingsController::class, 'index'])->name('index');
        Route::put('/', [ConciergeSettingsController::class, 'update'])->name('update');
        Route::post('wifi', [ConciergeSettingsController::class, 'storeWifi'])->name('wifi.store');
        Route::put('wifi/{wifiNetwork}', [ConciergeSettingsController::class, 'updateWifi'])->name('wifi.update');
        Route::delete('wifi/{wifiNetwork}', [ConciergeSettingsController::class, 'destroyWifi'])->name('wifi.destroy');
        Route::post('faqs', [ConciergeSettingsController::class, 'storeFaq'])->name('faqs.store');
        Route::delete('faqs/{faq}', [ConciergeSettingsController::class, 'destroyFaq'])->name('faqs.destroy');
        Route::put('amenities', [ConciergeSettingsController::class, 'toggleAmenity'])->name('amenities.toggle');
        Route::put('amenities/{amenity}', [ConciergeSettingsController::class, 'updateAmenity'])->whereNumber('amenity')->name('amenities.update');
        Route::prefix('nearby-places')->name('nearby-places.')->group(function () {
            Route::post('/', [NearbyPlaceController::class, 'store'])->name('store');
            Route::put('order', [NearbyPlaceController::class, 'reorder'])->name('reorder');
            Route::post('{nearbyPlace}/suggested', [NearbyPlaceController::class, 'storeSuggested'])->withoutScopedBindings()->whereNumber('nearbyPlace')->name('suggested');
            Route::put('{nearbyPlace}', [NearbyPlaceController::class, 'update'])->whereNumber('nearbyPlace')->name('update');
            Route::put('{nearbyPlace}/active', [NearbyPlaceController::class, 'active'])->whereNumber('nearbyPlace')->name('active.update');
            Route::delete('{nearbyPlace}', [NearbyPlaceController::class, 'destroy'])->whereNumber('nearbyPlace')->name('destroy');
        });
        Route::put('hotel-info', [ConciergeSettingsController::class, 'updateHotelInfo'])->name('hotel-info.update');
        Route::put('contacts', [ConciergeSettingsController::class, 'updateContacts'])->name('contacts.update');
        Route::post('topics', [ConciergeSettingsController::class, 'storeTopic'])->name('topics.store');
        Route::delete('topics/{infoTopic}', [ConciergeSettingsController::class, 'destroyTopic'])->name('topics.destroy');
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
        Route::get('{ratePlan}/companies', [RatePlanCompanyController::class, 'edit'])->name('companies.edit');
        Route::put('{ratePlan}/companies', [RatePlanCompanyController::class, 'update'])->name('companies.update');
        Route::post('{ratePlan}/companies', [RatePlanCompanyController::class, 'store'])->middleware('can:'.PermissionEnum::CompanyManage->value)->name('companies.store');
        Route::put('{ratePlan}/companies/{company}', [RatePlanCompanyController::class, 'updateRow'])->name('companies.rows.update');
        Route::delete('{ratePlan}/companies/{company}', [RatePlanCompanyController::class, 'destroy'])->middleware('can:'.PermissionEnum::CompanyManage->value)->name('companies.destroy');
        Route::post('{ratePlan}/discounts/copy', [RatePlanController::class, 'copyDiscounts'])->name('discounts.copy');
        Route::post('{ratePlan}/discounts/preview', [RatePlanController::class, 'previewDiscounts'])->name('discounts.preview');
        Route::get('{ratePlan}/pricing', [RatePlanController::class, 'editPricing'])->name('pricing.edit');
        Route::put('{ratePlan}/pricing', [RatePlanController::class, 'updatePricing'])->name('pricing.update');
        Route::put('{ratePlan}/pricing/package', [RatePlanController::class, 'updatePackagePricing'])->name('pricing.package.update');
        Route::put('{ratePlan}/pricing/derivation', [RatePlanController::class, 'updateDerivation'])->name('pricing.derivation.update');
        Route::post('{ratePlan}/pricing/derivation/preview', [RatePlanController::class, 'previewDerivation'])->name('pricing.derivation.preview');
        Route::post('{ratePlan}/pricing/extend', [RatePlanController::class, 'extendPricing'])->name('pricing.extend');
        Route::get('{ratePlan}/saved-prices', [RatePlanController::class, 'showSavedPrices'])->name('saved-prices.show');
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
