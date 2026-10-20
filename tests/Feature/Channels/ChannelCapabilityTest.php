<?php

use App\Enums\ChannelSellMode;
use App\Enums\ChannelSupport;
use App\Enums\PackageUnit;
use App\Enums\RateDerivation;
use App\Enums\Weekday;
use App\Models\PackagePrice;
use App\Models\Rate;
use App\Models\RateOccupancyPrice;
use App\Services\Channels\ChannelCapability;

require_once __DIR__.'/../Support/ChannelStructure.php';

test('per room and per guest plans on a room type with rooms are sent in full', function () {
    $property = channelProperty();
    $roomType = channelRoomType($property);
    $perRoom = channelRatePlan($property, [$roomType], 'Standard');
    $perGuest = channelRatePlan($property, [$roomType], 'Per head', 'perGuest');

    expect(app(ChannelCapability::class)->forPair($roomType, $perRoom)->support)->toBe(ChannelSupport::Full)
        ->and(app(ChannelCapability::class)->forPair($roomType, $perGuest)->support)->toBe(ChannelSupport::Full);
});

test('a day use plan is not sent and says why', function () {
    $property = channelProperty();
    $roomType = channelRoomType($property);
    $dayUse = channelRatePlan($property, [$roomType], 'Hourly', 'dayUse');

    $result = app(ChannelCapability::class)->forPair($roomType, $dayUse);

    expect($result->support)->toBe(ChannelSupport::Unsupported)
        ->and($result->detail)->toBe('Day use plans are sold at the front desk only.');
});

test('a bed room type is not sent and says why', function () {
    $property = channelProperty();
    $dorm = channelRoomType($property, '6-Bed Dorm', 1, ['sell_unit' => 'bed', 'max_adults' => 1, 'max_children' => 0, 'base_occupancy' => 1]);
    $bedRate = channelRatePlan($property, [$dorm], 'Bed rate', 'perBed');

    $result = app(ChannelCapability::class)->forPair($dorm, $bedRate);

    expect($result->support)->toBe(ChannelSupport::Unsupported)
        ->and($result->detail)->toBe('Dorm beds are not on channels yet.');
});

test('a room type with no active rooms is not sent', function () {
    $property = channelProperty();
    $empty = channelRoomType($property, 'Empty', 0);
    $plan = channelRatePlan($property, [$empty]);

    expect(app(ChannelCapability::class)->forPair($empty, $plan)->detail)->toBe('This room type has no active rooms.');
});

test('a package with one price is flattened, with the nights and arrival days in the note', function () {
    $property = channelProperty();
    $roomType = channelRoomType($property);
    $package = channelRatePlan($property, [$roomType], '3-night package', 'package', []);
    $package->update(['fixed_nights' => 3, 'package_unit' => PackageUnit::PerRoom]);
    PackagePrice::factory()->create(['rate_plan_id' => $package->id, 'room_type_id' => $roomType->id, 'amount' => '600.00']);
    $package->arrivalWeekdays()->create(['weekday' => Weekday::Friday]);

    $result = app(ChannelCapability::class)->forPair($roomType, $package->refresh());

    expect($result->support)->toBe(ChannelSupport::Flattened)
        ->and($result->detail)->toContain('Min stay = max stay 3')
        ->and($result->detail)->toContain('Friday');
});

test('a package whose price varies by arrival, or has none, is not sent', function () {
    $property = channelProperty();
    $roomType = channelRoomType($property);
    $varying = channelRatePlan($property, [$roomType], 'Varying', 'package');
    PackagePrice::factory()->create(['rate_plan_id' => $varying->id, 'room_type_id' => $roomType->id, 'amount' => '600.00']);
    PackagePrice::factory()->create(['rate_plan_id' => $varying->id, 'room_type_id' => $roomType->id, 'arrival_from' => '2026-07-01', 'arrival_to' => '2026-07-31', 'amount' => '700.00']);
    $unpriced = channelRatePlan($property, [$roomType], 'Unpriced', 'package');

    expect(app(ChannelCapability::class)->forPair($roomType, $varying->refresh())->detail)->toContain('varies by arrival')
        ->and(app(ChannelCapability::class)->forPair($roomType, $unpriced->refresh())->detail)->toContain('no price');
});

test('a per room plan is sold per adult count only when its price depends on the adults', function () {
    $capability = app(ChannelCapability::class);
    $property = channelProperty();
    $threeAdults = channelRoomType($property, 'Garden', attributes: ['max_adults' => 3, 'base_occupancy' => 2]);
    $twoAdults = channelRoomType($property, 'Twin', attributes: ['max_adults' => 2, 'base_occupancy' => 2]);
    $flat = channelRatePlan($property, [$threeAdults, $twoAdults], 'Flat');
    $extra = channelRatePlan($property, [$threeAdults, $twoAdults], 'Extra', attributes: ['extra_adult_amount' => '60.00']);
    $perGuest = channelRatePlan($property, [$threeAdults], 'Per head', 'perGuest');

    expect($capability->sellMode($flat, $threeAdults))->toBe(ChannelSellMode::PerRoom)
        ->and($capability->sellMode($extra, $threeAdults))->toBe(ChannelSellMode::PerPerson)
        ->and($capability->sellMode($extra, $twoAdults))->toBe(ChannelSellMode::PerRoom)
        ->and($capability->sellMode($perGuest, $threeAdults))->toBe(ChannelSellMode::PerPerson);
});

test('a plan with a price for an adult count is sold per adult count even with no extra adult amount', function () {
    $property = channelProperty();
    $roomType = channelRoomType($property, 'Garden', attributes: ['max_adults' => 3, 'base_occupancy' => 2]);
    $plan = channelRatePlan($property, [$roomType], 'Set prices');
    channelRate($plan, $roomType, '2026-10-14', '100.00');
    $rate = Rate::query()->where('rate_plan_id', $plan->id)->sole();
    RateOccupancyPrice::factory()->create(['rate_id' => $rate->id, 'adults' => 3, 'amount' => '140.00']);

    expect(app(ChannelCapability::class)->sellMode($plan, $roomType))->toBe(ChannelSellMode::PerPerson);
});

test('a plan that follows another uses the plan it follows', function () {
    $property = channelProperty();
    $roomType = channelRoomType($property, 'Garden', attributes: ['max_adults' => 3, 'base_occupancy' => 2]);
    $parent = channelRatePlan($property, [$roomType], 'Parent', attributes: ['extra_adult_amount' => '60.00', 'extra_child_amount' => '30.00']);
    $child = channelRatePlan($property, [$roomType], 'Child', attributes: ['parent_rate_plan_id' => $parent->id, 'derivation' => RateDerivation::Percent, 'derivation_value' => '-10.00']);

    $capability = app(ChannelCapability::class);

    expect($capability->sellMode($child, $roomType))->toBe(ChannelSellMode::PerPerson)
        ->and($capability->childFee($child))->toBe('30.00')
        ->and($capability->childFee(channelRatePlan($property, [$roomType], 'Per head', 'perGuest')))->toBe('0.00');
});
