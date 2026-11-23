<?php

require_once __DIR__.'/../Support/ChannelStructure.php';

use App\Enums\ChannelSellMode;
use App\Enums\ChannelSupport;
use App\Enums\DormStrategy;
use App\Models\Bed;
use App\Models\ChannelRatePlanMapping;
use App\Models\PlatformSetting;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\Channels\ChannelAriValues;
use App\Services\Channels\ChannelCapability;
use App\Services\Channels\ChannelRevisionMapper;
use App\Services\Channels\ChannelStructurePreview;
use App\Services\Channels\Channex\ChannexStructureMapper;
use Carbon\CarbonImmutable;

/**
 * A 6-bed dorm: one active room with six beds, one of them archived, and a per-bed plan.
 *
 * @return array{property: Property, dorm: RoomType, plan: RatePlan}
 */
function dormSetup(): array
{
    $property = channelProperty();
    $dorm = RoomType::factory()->for($property)->create(['name' => '6-Bed Dorm', 'sell_unit' => 'bed', 'max_adults' => 1, 'max_children' => 0, 'base_occupancy' => 1]);
    $room = Room::factory()->for($property)->create(['room_type_id' => $dorm->id]);
    Bed::factory()->count(5)->create(['room_id' => $room->id]);
    Bed::factory()->archived()->create(['room_id' => $room->id]);

    return ['property' => $property, 'dorm' => $dorm, 'plan' => channelRatePlan($property, [$dorm], 'Bed rate', 'perBed')];
}

test('a dorm goes to channels as one unit per active bed for one guest, with its per-bed plans', function () {
    $setup = dormSetup();
    $capability = app(ChannelCapability::class);
    $mapper = app(ChannexStructureMapper::class);
    $result = $capability->forPair($setup['dorm'], $setup['plan']);

    expect($result->support)->toBe(ChannelSupport::Full)
        ->and($capability->activeRoomCount($setup['dorm']))->toBe(5)
        ->and($capability->sellMode($setup['plan'], $setup['dorm']))->toBe(ChannelSellMode::PerRoom)
        ->and($mapper->roomType($setup['dorm'], 5))->toMatchArray([
            'count_of_rooms' => 5,
            'occ_adults' => 1,
            'occ_children' => 0,
            'default_occupancy' => 1,
            'room_kind' => 'room',
        ])
        ->and($mapper->ratePlan($setup['plan'], $setup['dorm'], $result, 'MYR'))->toMatchArray([
            'sell_mode' => 'per_room',
            'options' => [['occupancy' => 1, 'is_primary' => true, 'rate' => 0]],
        ]);

    $pair = collect(app(ChannelStructurePreview::class)->forProperty($setup['property'])->pairs)
        ->first(fn ($pair): bool => $pair->roomType->is($setup['dorm']) && $pair->ratePlan->is($setup['plan']));

    expect($pair->result->support)->toBe(ChannelSupport::Full)
        ->and($pair->roomType->getAttribute('active_beds_count'))->toBe(5);
});

test('a dorm refuses other plans, a room type refuses per-bed plans, and a dorm with no bed is not sent', function () {
    $setup = dormSetup();
    $capability = app(ChannelCapability::class);
    $roomPlan = channelRatePlan($setup['property'], [$setup['dorm']], 'Room rate');
    $room = channelRoomType($setup['property']);
    $emptyDorm = channelRoomType($setup['property'], 'Empty dorm', 1, ['sell_unit' => 'bed', 'max_adults' => 1, 'max_children' => 0, 'base_occupancy' => 1]);

    expect($capability->forPair($setup['dorm'], $roomPlan)->detail)->toBe('On channels a dorm sells its per-bed plans only.')
        ->and($capability->forPair($room, $setup['plan'])->detail)->toBe('A per-bed plan goes on channels with a dorm room type only.')
        ->and($capability->forPair($emptyDorm, $setup['plan'])->detail)->toBe('This dorm has no active beds.');
});

test('a dorm sends the bed price at occupancy one and its free beds', function () {
    $setup = dormSetup();
    channelRate($setup['plan'], $setup['dorm'], '2026-10-14', '45.00');
    $values = app(ChannelAriValues::class);
    $from = CarbonImmutable::parse('2026-10-14');

    expect($values->restrictions($setup['plan'], $setup['dorm'], $from, $from, 'MYR', ChannelSellMode::PerRoom)['2026-10-14'])->toMatchArray(['stop_sell' => false, 'rate' => '45.00'])
        ->and($values->availability([$setup['dorm']], $from, $from)[$setup['dorm']->id]['2026-10-14'])->toBe(['availability' => 5]);
});

test('a room of an OTA booking for a dorm is one bed for one guest', function () {
    $setup = dormSetup();
    $channelProperty = channelSyncedProperty($setup['property']);
    ChannelRatePlanMapping::factory()->create([
        'channel_property_id' => $channelProperty->id,
        'room_type_id' => $setup['dorm']->id,
        'rate_plan_id' => $setup['plan']->id,
        'external_rate_plan_id' => 'plan-dorm',
    ]);

    $units = app(ChannelRevisionMapper::class)->units($channelProperty, ['rooms' => [[
        'room_type_id' => 'room-dorm',
        'rate_plan_id' => 'plan-dorm',
        'checkin_date' => '2026-10-20',
        'checkout_date' => '2026-10-22',
        'amount' => '90.00',
        'occupancy' => ['adults' => 2, 'children' => 1, 'infants' => 0],
    ]]], 'Hostelworld');

    expect($units)->toHaveCount(1)
        ->and($units[0]->roomTypeId)->toBe($setup['dorm']->id)
        ->and($units[0]->adults)->toBe(1)
        ->and($units[0]->childAges)->toBe([])
        ->and($units[0]->beds)->toBe(1)
        ->and($units[0]->overrideTotal)->toBe('90.00');
});

test('native dorm stays off until it is verified, whatever the platform default says', function () {
    $setup = dormSetup();
    PlatformSetting::query()->update(['default_dorm_strategy' => DormStrategy::NativeDorm->value]);
    cache()->flush();

    expect(app(ChannelCapability::class)->forPair($setup['dorm'], $setup['plan'])->support)->toBe(ChannelSupport::Full)
        ->and(app(ChannexStructureMapper::class)->roomType($setup['dorm'], 5)['room_kind'])->toBe('room');
});
