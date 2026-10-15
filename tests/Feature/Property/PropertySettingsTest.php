<?php

use App\Actions\Onboarding\RegisterPropertyOwner;
use App\Data\Onboarding\NewPropertyOwnerData;
use App\Enums\Locale;
use App\Enums\PropertyType;
use App\Enums\WeekendNights;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function basicsInput(array $overrides = []): array
{
    return [
        'name' => 'Seaview Guesthouse',
        'type' => PropertyType::Homestay->value,
        'address_line1' => '12 Jalan Pantai',
        'city' => 'Kuching',
        ...$overrides,
    ];
}

function generalInput(array $overrides = []): array
{
    return [
        'currency' => 'MYR',
        'time_zone' => 'Asia/Kuala_Lumpur',
        'default_locale' => 'ms',
        'check_in_time' => '14:00',
        'check_out_time' => '12:00',
        'arrival_grace_minutes' => 30,
        'auto_assign_rooms' => 'on',
        ...$overrides,
    ];
}

test('new owners are sent to setup, and saving basics creates defaults', function () {
    $owner = app(RegisterPropertyOwner::class)(new NewPropertyOwnerData(name: 'Aina', email: 'aina@example.com', password: 'password', propertyName: 'Seaview Guesthouse'));
    $owner->forceFill(['email_verified_at' => now()])->save();
    $property = $owner->property;

    $this->actingAs($owner)->get(route('settings.edit', $property->code))
        ->assertRedirect(route('onboarding.basics.edit', $property->code));

    $this->put(route('onboarding.basics.update', $property->code), basicsInput())
        ->assertRedirect(route('settings.edit', $property->code))
        ->assertSessionHasNoErrors();

    $settings = $property->refresh()->settings;
    expect($property->type)->toBe(PropertyType::Homestay)
        ->and($property->country_code)->toBe('MY')
        ->and($settings->currency->getCurrencyCode())->toBe('MYR')
        ->and($settings->time_zone)->toBe('Asia/Kuala_Lumpur')
        ->and($settings->default_locale)->toBe(Locale::English)
        ->and($settings->check_in_time)->toBe('15:00:00')
        ->and($settings->check_out_time)->toBe('11:00:00')
        ->and($settings->audit_time)->toBe('02:00:00')
        ->and($settings->auto_assign_rooms)->toBeFalse()
        ->and($settings->auto_audit_enabled)->toBeFalse()
        ->and($settings->missed_arrival_policy->value)->toBe('keep_as_late_arrival')
        ->and($settings->overdue_departure_policy->value)->toBe('block')
        ->and($settings->registration_card_scope->value)->toBe('primary_only')
        ->and($settings->card_required_for_check_in)->toBeFalse()
        ->and($settings->id_document_retention_days)->toBeNull()
        ->and($settings->security_deposit_amount)->toBe('0.00')
        ->and($settings->allow_support_access)->toBeTrue();
});

test('saving basics again preserves edited settings', function () {
    $staff = User::factory()->owner()->for(Property::factory()->onboarding())->create();
    $property = $staff->property;
    $settings = PropertySetting::factory()->for($property)->create(['default_locale' => 'ms']);

    $this->actingAs($staff)->put(route('onboarding.basics.update', $property->code), basicsInput(['name' => 'New name']))
        ->assertSessionHasNoErrors();

    expect($property->refresh()->name)->toBe('New name')
        ->and($settings->refresh()->default_locale)->toBe(Locale::Malay)
        ->and(PropertySetting::query()->where('property_id', $property->id)->count())->toBe(1);
});

test('settings sections save independently', function () {
    $staff = User::factory()->owner()->create();
    $property = $staff->property;
    $settings = PropertySetting::factory()->for($property)->create();

    $this->actingAs($staff)->put(route('settings.update', [$property->code, 'general']), generalInput())
        ->assertRedirect(route('settings.edit', $property->code))->assertSessionHasNoErrors();
    $this->put(route('settings.update', [$property->code, 'operations']), [
        'audit_time' => '03:30', 'auto_audit_enabled' => 'on',
        'missed_arrival_policy' => 'mark_no_show', 'overdue_departure_policy' => 'extend_one_night',
        'weekend_nights' => 'sat_sun',
    ])->assertRedirect(route('settings.operations', $property->code))->assertSessionHasNoErrors();
    $this->put(route('settings.registration-card.update', $property->code), [
        'registration_card_scope' => 'every_adult', 'card_required_for_check_in' => 'on',
        'id_document_retention_days' => 30, 'security_deposit_amount' => '150.00',
    ])->assertSessionHasNoErrors();
    $this->put(route('settings.update', [$property->code, 'support']), [])->assertSessionHasNoErrors();

    $settings->refresh();
    expect($settings->default_locale)->toBe(Locale::Malay)
        ->and($settings->check_in_time)->toBe('14:00:00')
        ->and($settings->auto_assign_rooms)->toBeTrue()
        ->and($settings->audit_time)->toBe('03:30:00')
        ->and($settings->weekend_nights)->toBe(WeekendNights::SatSun)
        ->and($settings->auto_audit_enabled)->toBeTrue()
        ->and($settings->registration_card_scope->value)->toBe('every_adult')
        ->and($settings->security_deposit_amount)->toBe('150.00')
        ->and($settings->allow_support_access)->toBeFalse();

    $this->get(route('settings.edit', $property->code))->assertInertia(fn (Assert $page) => $page
        ->component('settings/edit')->where('settings.defaultLocale', 'ms')
        ->where('settings.allowSupportAccess', false));
});

test('each settings tab has its own url and sends only its options', function () {
    $staff = User::factory()->owner()->create();
    $property = $staff->property;
    PropertySetting::factory()->for($property)->create();

    $this->actingAs($staff)->get(route('settings.edit', $property->code))
        ->assertInertia(fn (Assert $page) => $page->component('settings/edit')->where('tab', 'general')
            ->has('currencies')->has('locales')->missing('missedArrivalPolicies'));
    $this->get(route('settings.operations', $property->code))
        ->assertInertia(fn (Assert $page) => $page->where('tab', 'operations')
            ->has('missedArrivalPolicies')->missing('currencies'));
    $this->get(route('settings.support', $property->code))
        ->assertInertia(fn (Assert $page) => $page->where('tab', 'support')->missing('missedArrivalPolicies'));
});

test('invalid values and missing setup cannot update settings', function () {
    $staff = User::factory()->owner()->for(Property::factory()->live())->create();
    $property = $staff->property;

    $this->actingAs($staff)->put(route('settings.update', [$property->code, 'support']), [])->assertNotFound();
    $settings = PropertySetting::factory()->for($property)->create();
    $checkInTime = $settings->refresh()->check_in_time;

    $this->put(route('settings.update', [$property->code, 'general']), generalInput([
        'check_in_time' => 'noon', 'arrival_grace_minutes' => -5,
    ]))->assertSessionHasErrors(['check_in_time', 'arrival_grace_minutes']);
    $this->put(route('settings.registration-card.update', $property->code), [
        'registration_card_scope' => 'primary_only', 'security_deposit_amount' => '1.999',
    ])->assertSessionHasErrors(['security_deposit_amount']);

    expect($settings->refresh()->check_in_time)->toBe($checkInTime);

    $onboarding = User::factory()->owner()->for(Property::factory()->onboarding())->create();

    $this->actingAs($onboarding)->put(route('onboarding.basics.update', $onboarding->property->code), basicsInput(['type' => 'invalid']))
        ->assertSessionHasErrors(['type']);

    expect($onboarding->property->refresh()->type)->toBe(PropertyType::Hotel);
});

test('staff cannot read or update another property', function () {
    $staff = User::factory()->owner()->create();
    $other = User::factory()->create()->property;
    PropertySetting::factory()->for($other)->create();

    $this->actingAs($staff)->get(route('settings.edit', $other->code))->assertNotFound();
    $this->put(route('settings.update', [$other->code, 'support']), [])->assertNotFound();
    $this->get(route('onboarding.basics.edit', $other->code))->assertNotFound();
    $this->put(route('onboarding.basics.update', $other->code), basicsInput())->assertNotFound();

    expect($other->settings->allow_support_access)->toBeTrue();
});

test('currency and time zone are fixed after the property goes live', function () {
    $property = Property::factory()->live()->create();
    $staff = User::factory()->for($property)->owner()->create();
    $settings = PropertySetting::factory()->for($property)->create();

    $this->actingAs($staff)->get(route('settings.edit', $property->code))
        ->assertInertia(fn (Assert $page) => $page->where('settings.currencyAndTimeZoneLocked', true));

    $this->put(route('settings.update', [$property->code, 'general']), generalInput([
        'currency' => 'USD', 'time_zone' => 'Asia/Singapore',
    ]))->assertSessionHasNoErrors();

    expect($settings->refresh()->time_zone)->toBe('Asia/Kuala_Lumpur')
        ->and($settings->currency->getCurrencyCode())->toBe('MYR')
        ->and($settings->default_locale)->toBe(Locale::Malay);
});

test('the ID document retention is blank or at least one day', function () {
    $staff = User::factory()->owner()->create();
    $property = $staff->property;
    $settings = PropertySetting::factory()->for($property)->create();
    $url = route('settings.registration-card.update', $property->code);
    $payload = ['registration_card_scope' => 'primary_only', 'security_deposit_amount' => '0.00'];

    $this->actingAs($staff)->put($url, [...$payload, 'id_document_retention_days' => 0])->assertSessionHasErrors('id_document_retention_days');
    $this->put($url, [...$payload, 'id_document_retention_days' => 1])->assertSessionHasNoErrors();
    expect($settings->refresh()->id_document_retention_days)->toBe(1);

    $this->put($url, [...$payload, 'id_document_retention_days' => ''])->assertSessionHasNoErrors();
    expect($settings->refresh()->id_document_retention_days)->toBeNull();
});

test('the money corrections switch defaults on and saves on the support section', function () {
    $staff = User::factory()->owner()->create();
    $property = $staff->property;
    $settings = PropertySetting::factory()->for($property)->create();

    expect($settings->refresh()->allow_support_money_corrections)->toBeTrue();

    $this->actingAs($staff)->put(route('settings.update', [$property->code, 'support']), ['allow_support_access' => '1', 'allow_support_money_corrections' => '0'])
        ->assertSessionHasNoErrors();

    expect($settings->refresh()->allow_support_money_corrections)->toBeFalse()
        ->and($settings->allow_support_access)->toBeTrue();
});
