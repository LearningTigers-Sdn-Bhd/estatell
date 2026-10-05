<?php

use App\Enums\PolicyRateBasis;
use App\Enums\PolicyScope;
use App\Enums\SystemTransactionCode;
use App\Models\BusinessDate;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Rate;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\RoomRevenuePolicy;
use App\Models\UnitNight;
use App\Services\Catalogues\PolicyChargeCalculator;
use Illuminate\Support\Facades\Date;

/**
 * A unit from 2026-11-02 to 2026-11-05 with nights booked at 100.00, 120.00 and 140.00.
 *
 * @return array{0: Property, 1: ReservationUnit}
 */
function policyUnit(): array
{
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create();
    $unit = ReservationUnit::factory()->for(Reservation::factory()->for($property))->withNights()->create();

    foreach (['2026-11-02' => '100.00', '2026-11-03' => '120.00', '2026-11-04' => '140.00'] as $date => $amount) {
        UnitNight::query()->where('reservation_unit_id', $unit->id)->whereDate('date', $date)->update(['base_amount' => $amount, 'amount' => $amount]);
    }

    return [$property, $unit];
}

function policyFor(Property $property, SystemTransactionCode $code, PolicyScope $scope, ?string $percent = null, PolicyRateBasis $basis = PolicyRateBasis::BookedRate, ?int $freeUntilDays = null): RoomRevenuePolicy
{
    $factory = RoomRevenuePolicy::factory()->for($property)->forCode($code, $scope);

    return ($percent === null ? $factory : $factory->percentage($percent, $basis))->create(['free_until_days' => $freeUntilDays]);
}

test('a free policy charges nothing', function () {
    [$property, $unit] = policyUnit();
    $policy = policyFor($property, SystemTransactionCode::NoShowRevenue, PolicyScope::FirstNight);

    $charge = app(PolicyChargeCalculator::class)->calculate($policy, $unit);

    expect($charge->isFree())->toBeTrue()->and($charge->withinFreeWindow)->toBeFalse();
});

test('a percentage of the first night uses the first night booked amount', function () {
    [$property, $unit] = policyUnit();
    $policy = policyFor($property, SystemTransactionCode::NoShowRevenue, PolicyScope::FirstNight, '50.00');

    expect((string) app(PolicyChargeCalculator::class)->calculate($policy, $unit)->amount->getAmount())->toBe('50.00');
});

test('a percentage of the whole stay uses every booked night', function () {
    [$property, $unit] = policyUnit();
    $policy = policyFor($property, SystemTransactionCode::NoShowRevenue, PolicyScope::WholeStay, '100.00');

    expect((string) app(PolicyChargeCalculator::class)->calculate($policy, $unit)->amount->getAmount())->toBe('360.00');
});

test('a fixed amount is one charge whatever the nights', function () {
    [$property, $unit] = policyUnit();
    $policy = RoomRevenuePolicy::factory()->for($property)->fixed('75.00')
        ->forCode(SystemTransactionCode::CancellationRevenue, PolicyScope::WholeStay)->create();

    $charge = app(PolicyChargeCalculator::class)->calculate($policy, $unit);

    expect((string) $charge->amount->getAmount())->toBe('75.00')->and($charge->amount->getCurrency()->getCurrencyCode())->toBe('MYR');
});

test('unused nights are the booked nights from the new departure date', function () {
    [$property, $unit] = policyUnit();
    $policy = policyFor($property, SystemTransactionCode::EarlyDepartureRevenue, PolicyScope::UnusedNights, '100.00');

    $charge = app(PolicyChargeCalculator::class)->calculate($policy, $unit, Date::parse('2026-11-04'));

    expect((string) $charge->amount->getAmount())->toBe('140.00');
});

test('no unused nights means no charge, even for a fixed amount', function () {
    [$property, $unit] = policyUnit();
    $policy = RoomRevenuePolicy::factory()->for($property)->fixed('60.00')
        ->forCode(SystemTransactionCode::EarlyDepartureRevenue, PolicyScope::UnusedNights)->create();

    expect(app(PolicyChargeCalculator::class)->calculate($policy, $unit, Date::parse('2026-11-05'))->isFree())->toBeTrue();
});

test('unused nights need the new departure date', function () {
    [$property, $unit] = policyUnit();
    $policy = policyFor($property, SystemTransactionCode::EarlyDepartureRevenue, PolicyScope::UnusedNights, '100.00');

    app(PolicyChargeCalculator::class)->calculate($policy, $unit);
})->throws(InvalidArgumentException::class);

test('a late check-out is a percentage of the last night', function () {
    [$property, $unit] = policyUnit();
    $policy = policyFor($property, SystemTransactionCode::LateCheckoutRevenue, PolicyScope::OneCharge, '50.00');

    expect((string) app(PolicyChargeCalculator::class)->calculate($policy, $unit)->amount->getAmount())->toBe('70.00');
});

test('the current rate is a fresh quote, and falls back to the booked rate without one', function () {
    [$property, $unit] = policyUnit();
    $unit->ratePlan->roomTypes()->attach($unit->room_type_id);
    $policy = policyFor($property, SystemTransactionCode::NoShowRevenue, PolicyScope::FirstNight, '100.00', PolicyRateBasis::CurrentRate);

    expect((string) app(PolicyChargeCalculator::class)->calculate($policy, $unit)->amount->getAmount())->toBe('100.00');

    Rate::factory()->create([
        'rate_plan_id' => $unit->rate_plan_id, 'room_type_id' => $unit->room_type_id, 'date' => '2026-11-02', 'amount' => '200.00',
    ]);

    expect((string) app(PolicyChargeCalculator::class)->calculate($policy, $unit)->amount->getAmount())->toBe('200.00');
});

test('a cancellation inside the free window is free and outside it follows the charge', function (string $businessDate, bool $free) {
    [$property, $unit] = policyUnit();
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => $businessDate]);
    $policy = policyFor($property, SystemTransactionCode::CancellationRevenue, PolicyScope::FirstNight, '100.00', freeUntilDays: 2);

    $charge = app(PolicyChargeCalculator::class)->calculate($policy, $unit);

    expect($charge->isFree())->toBe($free)->and($charge->withinFreeWindow)->toBe($free);
})->with([
    'five days before' => ['2026-10-28', true],
    'two days before' => ['2026-10-31', true],
    'one day before' => ['2026-11-01', false],
    'on arrival' => ['2026-11-02', false],
]);

test('rounding is half up to the cent', function () {
    [$property, $unit] = policyUnit();
    UnitNight::query()->where('reservation_unit_id', $unit->id)->whereDate('date', '2026-11-02')->update(['base_amount' => '99.99', 'amount' => '99.99']);
    $policy = policyFor($property, SystemTransactionCode::NoShowRevenue, PolicyScope::FirstNight, '33.33');

    expect((string) app(PolicyChargeCalculator::class)->calculate($policy, $unit)->amount->getAmount())->toBe('33.33');
});
