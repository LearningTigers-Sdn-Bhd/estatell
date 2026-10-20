<?php

use App\Enums\ChannelSupport;
use App\Enums\PackageUnit;
use App\Enums\Weekday;
use App\Models\PackagePrice;
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
