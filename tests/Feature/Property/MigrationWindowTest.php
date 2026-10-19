<?php

use App\Actions\Catalogues\SeedPaymentMethods;
use App\Actions\Catalogues\SeedTransactionCodes;
use App\Enums\PaymentMethodType;
use App\Enums\PermissionEnum;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionCategory;
use App\Enums\TransactionKind;
use App\Models\BusinessDate;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\User;
use App\Services\Property\CountryProfiles;
use App\Services\Property\MigrationWindow;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/** A live property that went live on 2026-10-01 with the given window, and a business date. */
function windowProperty(int $days, string $currentDate = '2026-10-01', string $goLiveDate = '2026-10-01'): Property
{
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create(['migration_window_days' => $days]);
    BusinessDate::factory()->closed()->create(['property_id' => $property->id, 'date' => $goLiveDate]);

    if ($currentDate !== $goLiveDate) {
        BusinessDate::factory()->create(['property_id' => $property->id, 'date' => $currentDate]);
    } else {
        $property->businessDates()->update(['status' => 'open', 'closed_at' => null]);
    }

    return $property;
}

test('the window is closed before go-live', function () {
    $property = Property::factory()->create();
    PropertySetting::factory()->for($property)->create(['migration_window_days' => 60]);

    expect(app(MigrationWindow::class)->isOpen($property))->toBeFalse()
        ->and(app(MigrationWindow::class)->closesOn($property))->toBeNull();
});

test('the window is open through its last day and closed from the first day after', function () {
    $window = app(MigrationWindow::class);

    $lastOpen = windowProperty(60, '2026-11-29');
    $firstClosed = windowProperty(60, '2026-11-30');
    $goLiveDay = windowProperty(60);

    expect($window->isOpen($lastOpen))->toBeTrue()
        ->and($window->closesOn($lastOpen)->toDateString())->toBe('2026-11-30')
        ->and($window->isOpen($firstClosed))->toBeFalse()
        ->and($window->isOpen($goLiveDay))->toBeTrue();
});

test('a window of 0 days is always closed', function () {
    $property = windowProperty(0);

    expect(app(MigrationWindow::class)->isOpen($property))->toBeFalse()
        ->and(app(MigrationWindow::class)->closesOn($property))->toBeNull();
});

test('seeding adds the two codes and the inactive Old system method, and seeding twice adds no duplicates', function () {
    foreach (['MY', 'SG'] as $country) {
        $property = Property::factory()->create(['country_code' => $country]);
        $profile = CountryProfiles::for($country);

        foreach (range(1, 2) as $run) {
            app(SeedTransactionCodes::class)($property, $profile);
            app(SeedPaymentMethods::class)($property, $profile);
        }

        $opening = $property->transactionCodes()->where('system_key', 'opening_balance')->sole();
        $oldSystem = $property->transactionCodes()->where('system_key', 'old_system_payment')->sole();
        $method = $property->paymentMethods()->where('type', PaymentMethodType::OldSystem)->sole();

        expect($opening->code)->toBe('OPENING_BAL')
            ->and($opening->name)->toBe('Opening balance')
            ->and($opening->kind)->toBe(TransactionKind::Adjustment)
            ->and($opening->category)->toBe(TransactionCategory::OpeningBalance)
            ->and($oldSystem->code)->toBe('OLD_SYSTEM')
            ->and($oldSystem->kind)->toBe(TransactionKind::Payment)
            ->and($oldSystem->category)->toBe(TransactionCategory::OldSystem)
            ->and($method->active)->toBeFalse()
            ->and($method->requires_reference)->toBeTrue()
            ->and($method->transaction_code_id)->toBe($oldSystem->id);
    }
});

test('Old system is system-only and has no reference by default', function () {
    expect(PaymentMethodType::addableCases())->not->toContain(PaymentMethodType::OldSystem)
        ->and(PaymentMethodType::OldSystem->requiresReferenceByDefault())->toBeFalse()
        ->and(PaymentMethodType::OldSystem->category())->toBe(TransactionCategory::OldSystem)
        ->and(SystemTransactionCode::OldSystemPayment->category())->toBe(TransactionCategory::OldSystem);
});

test('Settings payment methods does not list Old system', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create();
    app(SeedTransactionCodes::class)($property, CountryProfiles::for('MY'));
    app(SeedPaymentMethods::class)($property, CountryProfiles::for('MY'));

    $this->actingAs($owner)->get(route('settings.payment-methods.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/payment-methods/index')
            ->where('paymentMethods', fn ($methods) => collect($methods)->doesntContain('type', 'old_system') && collect($methods)->contains('type', 'cash')));
});

test('the backfill gives an existing property the codes and the method, and its down removes unused rows', function () {
    $malaysian = Property::factory()->create(['country_code' => 'MY']);
    $other = Property::factory()->create(['country_code' => 'SG']);

    foreach ([$malaysian, $other] as $property) {
        $property->transactionCodes()->whereIn('system_key', ['opening_balance', 'old_system_payment'])->delete();
    }

    $migration = require database_path('migrations/2026_10_19_193225_backfill_migration_codes_and_old_system_method.php');
    $migration->up();
    $migration->up();

    foreach ([$malaysian, $other] as $property) {
        expect($property->transactionCodes()->where('system_key', 'opening_balance')->count())->toBe(1)
            ->and($property->transactionCodes()->where('system_key', 'old_system_payment')->count())->toBe(1);

        $method = $property->paymentMethods()->where('type', PaymentMethodType::OldSystem)->sole();
        expect($method->active)->toBeFalse()->and($method->requires_reference)->toBeTrue();
    }

    $migration->down();

    expect(PaymentMethod::query()->where('type', PaymentMethodType::OldSystem)->exists())->toBeFalse()
        ->and(DB::table('transaction_codes')->whereIn('system_key', ['opening_balance', 'old_system_payment'])->exists())->toBeFalse();
});

test('settings accept a window from 0 to 365 and refuse the rest', function (mixed $days, bool $valid) {
    $owner = User::factory()->owner()->for(Property::factory()->live())->create();
    $property = $owner->property;
    $settings = PropertySetting::factory()->for($property)->create();

    $response = $this->actingAs($owner)->put(route('settings.update', [$property->code, 'operations']), [
        'audit_time' => '03:30', 'missed_arrival_policy' => 'mark_no_show', 'overdue_departure_policy' => 'block',
        'weekend_nights' => 'fri_sat', 'migration_window_days' => $days,
    ]);

    if ($valid) {
        $response->assertSessionHasNoErrors();
        expect($settings->refresh()->migration_window_days)->toBe((int) $days);
    } else {
        $response->assertSessionHasErrors('migration_window_days');
        expect($settings->refresh()->migration_window_days)->toBe(60);
    }
})->with([[0, true], [365, true], [366, false], [-1, false], ['', false]]);

test('the Operations tab sends the window and needs settings.manage', function () {
    $owner = User::factory()->owner()->for(Property::factory()->live())->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create(['migration_window_days' => 45]);

    $this->actingAs($owner)->get(route('settings.operations', $property->code))
        ->assertInertia(fn (Assert $page) => $page->where('settings.migrationWindowDays', 45));

    $staff = User::factory()->for($property)->withPermissions([PermissionEnum::StaffManage])->create();

    $this->actingAs($staff)->get(route('settings.operations', $property->code))->assertForbidden();
    $this->put(route('settings.update', [$property->code, 'operations']), ['migration_window_days' => 10])->assertForbidden();
});
