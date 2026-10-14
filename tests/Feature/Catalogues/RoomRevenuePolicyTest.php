<?php

use App\Actions\Catalogues\SaveRoomRevenuePolicy;
use App\Actions\Catalogues\SeedRoomRevenuePolicies;
use App\Enums\PolicyChargeMode;
use App\Enums\PolicyRateBasis;
use App\Enums\PolicyScope;
use App\Enums\SystemTransactionCode;
use App\Models\Property;
use App\Models\RoomRevenuePolicy;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('seeding creates four free policies with a default text in both languages', function () {
    $property = Property::factory()->create();

    app(SeedRoomRevenuePolicies::class)($property);

    $policies = $property->roomRevenuePolicies()->get()->keyBy(fn (RoomRevenuePolicy $policy) => $policy->code->value);

    expect($policies)->toHaveCount(4)
        ->and($policies->every(fn (RoomRevenuePolicy $policy) => $policy->charge_mode === PolicyChargeMode::Free))->toBeTrue()
        ->and($policies->every(fn (RoomRevenuePolicy $policy) => $policy->text_en !== '' && $policy->text_ms !== ''))->toBeTrue()
        ->and($policies['no_show_revenue']->applies_to)->toBe(PolicyScope::FirstNight)
        ->and($policies['cancellation_revenue']->applies_to)->toBe(PolicyScope::FirstNight)
        ->and($policies['early_departure_revenue']->applies_to)->toBe(PolicyScope::UnusedNights)
        ->and($policies['late_checkout_revenue']->applies_to)->toBe(PolicyScope::OneCharge);
});

test('seeding twice keeps edited rows', function () {
    $property = Property::factory()->create();
    app(SeedRoomRevenuePolicies::class)($property);
    $property->roomRevenuePolicies()->where('code', SystemTransactionCode::NoShowRevenue->value)
        ->update(['charge_mode' => PolicyChargeMode::Percentage, 'percent' => '100.00', 'percent_basis' => PolicyRateBasis::BookedRate, 'text_en' => 'Custom']);

    app(SeedRoomRevenuePolicies::class)($property);

    $noShow = $property->roomRevenuePolicies()->where('code', SystemTransactionCode::NoShowRevenue->value)->sole();
    expect($property->roomRevenuePolicies()->count())->toBe(4)
        ->and($noShow->charge_mode)->toBe(PolicyChargeMode::Percentage)
        ->and($noShow->text_en)->toBe('Custom');
});

test('saving a percentage policy clears the amount and logs the change', function () {
    $staff = User::factory()->owner()->create();
    $property = $staff->property;
    app(SeedRoomRevenuePolicies::class)($property);

    $policy = app(SaveRoomRevenuePolicy::class)($property, $staff, SystemTransactionCode::CancellationRevenue, [
        'charge_mode' => 'percentage', 'amount' => '99.00', 'percent' => '50', 'percent_basis' => 'booked_rate',
        'applies_to' => 'whole_stay', 'free_until_days' => 2, 'text_en' => 'Pay half.', 'text_ms' => 'Bayar separuh.',
    ]);

    expect($policy->charge_mode)->toBe(PolicyChargeMode::Percentage)
        ->and($policy->amount)->toBeNull()
        ->and($policy->percent)->toBe('50.00')
        ->and($policy->percent_basis)->toBe(PolicyRateBasis::BookedRate)
        ->and($policy->applies_to)->toBe(PolicyScope::WholeStay)
        ->and($policy->free_until_days)->toBe(2)
        ->and($policy->text_ms)->toBe('Bayar separuh.');
    $this->assertDatabaseHas('activity_log', ['event' => 'room_revenue_policy.updated', 'causer_id' => $staff->id]);
});

test('saving a free or fixed policy clears the unused fields, and only cancellation keeps a free window', function () {
    $staff = User::factory()->owner()->create();
    $property = $staff->property;
    app(SeedRoomRevenuePolicies::class)($property);

    $fixed = app(SaveRoomRevenuePolicy::class)($property, $staff, SystemTransactionCode::NoShowRevenue, [
        'charge_mode' => 'fixed_amount', 'amount' => '40', 'percent' => '10', 'percent_basis' => 'current_rate',
        'applies_to' => 'first_night', 'free_until_days' => 3, 'text_en' => 'Fee.', 'text_ms' => 'Yuran.',
    ]);

    expect($fixed->amount)->toBe('40.00')
        ->and($fixed->percent)->toBeNull()
        ->and($fixed->percent_basis)->toBeNull()
        ->and($fixed->free_until_days)->toBeNull();

    $free = app(SaveRoomRevenuePolicy::class)($property, $staff, SystemTransactionCode::NoShowRevenue, [
        'charge_mode' => 'free', 'amount' => '40', 'percent' => '10', 'percent_basis' => 'current_rate',
        'applies_to' => 'first_night', 'text_en' => 'Free.', 'text_ms' => 'Percuma.',
    ]);

    expect($free->amount)->toBeNull()->and($free->percent)->toBeNull();
});

test('saving unchanged values logs nothing', function () {
    $staff = User::factory()->owner()->create();
    $property = $staff->property;
    app(SeedRoomRevenuePolicies::class)($property);
    $policy = $property->roomRevenuePolicies()->where('code', SystemTransactionCode::LateCheckoutRevenue->value)->sole();

    app(SaveRoomRevenuePolicy::class)($property, $staff, SystemTransactionCode::LateCheckoutRevenue, [
        'charge_mode' => 'free', 'applies_to' => 'one_charge', 'text_en' => $policy->text_en, 'text_ms' => $policy->text_ms,
    ]);

    $this->assertDatabaseMissing('activity_log', ['event' => 'room_revenue_policy.updated']);
});

test('a policy is saved per property', function () {
    $staff = User::factory()->owner()->create();
    $other = Property::factory()->create();
    app(SeedRoomRevenuePolicies::class)($staff->property);
    app(SeedRoomRevenuePolicies::class)($other);

    app(SaveRoomRevenuePolicy::class)($staff->property, $staff, SystemTransactionCode::NoShowRevenue, [
        'charge_mode' => 'fixed_amount', 'amount' => '25', 'applies_to' => 'first_night', 'text_en' => 'Fee.', 'text_ms' => 'Yuran.',
    ]);

    expect($other->roomRevenuePolicies()->where('charge_mode', '!=', 'free')->count())->toBe(0);
});

test('the database refuses a second row per code and a code that has no policy', function () {
    $property = Property::factory()->create();
    app(SeedRoomRevenuePolicies::class)($property);

    expect(fn () => DB::transaction(fn () => RoomRevenuePolicy::factory()->for($property)->forCode(SystemTransactionCode::NoShowRevenue, PolicyScope::FirstNight)->create()))
        ->toThrow(QueryException::class);

    expect(fn () => DB::transaction(fn () => RoomRevenuePolicy::factory()->for($property)->forCode(SystemTransactionCode::RoomRevenue, PolicyScope::FirstNight)->create()))
        ->toThrow(QueryException::class);
});

test('each policy code allows only its own scopes', function () {
    expect(PolicyScope::allowedFor(SystemTransactionCode::NoShowRevenue))->toBe([PolicyScope::FirstNight, PolicyScope::WholeStay])
        ->and(PolicyScope::allowedFor(SystemTransactionCode::CancellationRevenue))->toBe([PolicyScope::FirstNight, PolicyScope::WholeStay])
        ->and(PolicyScope::allowedFor(SystemTransactionCode::EarlyDepartureRevenue))->toBe([PolicyScope::UnusedNights])
        ->and(PolicyScope::allowedFor(SystemTransactionCode::LateCheckoutRevenue))->toBe([PolicyScope::OneCharge])
        ->and(PolicyScope::allowedFor(SystemTransactionCode::RoomRevenue))->toBe([]);
});
