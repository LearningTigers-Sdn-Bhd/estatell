<?php

use App\Actions\Property\SeedPropertyDefaults;
use App\Enums\Currency;
use App\Enums\DefaultRoleEnum;
use App\Enums\Locale;
use App\Enums\MissedArrivalPolicy;
use App\Enums\NoShowCharge;
use App\Enums\OverdueDeparturePolicy;
use App\Enums\PermissionEnum;
use App\Enums\PropertyType;
use App\Enums\RegistrationCardScope;
use App\Models\Property;
use App\Models\Role;

test('each property type receives the Malaysia defaults', function (PropertyType $type) {
    $property = Property::factory()->create(['type' => $type, 'country_code' => 'MY']);

    app(SeedPropertyDefaults::class)($property, $type, 'MY');

    $settings = $property->settings;

    expect($property->roles()->count())->toBe(5)
        ->and($property->settings()->count())->toBe(1)
        ->and($settings->currency)->toBe(Currency::Myr)
        ->and($settings->time_zone)->toBe('Asia/Kuala_Lumpur')
        ->and($settings->default_locale)->toBe(Locale::English)
        ->and($settings->check_in_time)->toBe('15:00:00')
        ->and($settings->check_out_time)->toBe('11:00:00')
        ->and($settings->arrival_grace_minutes)->toBe(0)
        ->and($settings->auto_assign_rooms)->toBeFalse()
        ->and($settings->audit_time)->toBe('02:00:00')
        ->and($settings->auto_audit_enabled)->toBeFalse()
        ->and($settings->missed_arrival_policy)->toBe(MissedArrivalPolicy::KeepAsLateArrival)
        ->and($settings->overdue_departure_policy)->toBe(OverdueDeparturePolicy::Block)
        ->and($settings->no_show_charge)->toBe(NoShowCharge::None)
        ->and($settings->registration_card_scope)->toBe(RegistrationCardScope::PrimaryOnly)
        ->and($settings->card_required_for_check_in)->toBeFalse()
        ->and($settings->id_document_retention_days)->toBeNull()
        ->and($settings->security_deposit_amount)->toBe('0.00')
        ->and($settings->allow_support_access)->toBeTrue();
})->with(PropertyType::cases());

test('seeding again preserves edited settings and roles', function () {
    $property = Property::factory()->create();
    $seedPropertyDefaults = app(SeedPropertyDefaults::class);
    $seedPropertyDefaults($property, $property->type, $property->country_code);
    $settings = $property->settings;
    $settings->update(['default_locale' => Locale::Malay]);
    $manager = Role::query()
        ->where('property_id', $property->id)
        ->where('default_role', DefaultRoleEnum::Manager)
        ->firstOrFail();
    $manager->revokePermissionTo(PermissionEnum::SettingsManage->value);

    $seedPropertyDefaults($property, $property->type, $property->country_code);

    expect($property->roles()->count())->toBe(5)
        ->and($property->settings()->count())->toBe(1)
        ->and($property->settings()->firstOrFail()->id)->toBe($settings->id)
        ->and($settings->refresh()->default_locale)->toBe(Locale::Malay)
        ->and($manager->permissions()->pluck('name')->all())->not->toContain(PermissionEnum::SettingsManage->value);
});

test('unsupported countries create no defaults', function () {
    $property = Property::factory()->create();

    expect(fn () => app(SeedPropertyDefaults::class)($property, $property->type, 'my'))
        ->toThrow(InvalidArgumentException::class);

    expect($property->roles()->count())->toBe(0)
        ->and($property->settings()->count())->toBe(0);
});
