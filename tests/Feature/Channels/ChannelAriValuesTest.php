<?php

use App\Enums\ChannelSellMode;
use App\Enums\PackageUnit;
use App\Enums\RateDerivation;
use App\Enums\Weekday;
use App\Models\PackagePrice;
use App\Services\Channels\ChannelAriValues;
use App\Services\Channels\ChannelCapability;
use Carbon\CarbonImmutable;

require_once __DIR__.'/../Support/ChannelStructure.php';

function ariValues($setup, string $from = '2026-10-14', string $to = '2026-10-16'): array
{
    $sellMode = app(ChannelCapability::class)->sellMode($setup['ratePlan'], $setup['roomType']);

    return app(ChannelAriValues::class)->restrictions($setup['ratePlan'], $setup['roomType'], CarbonImmutable::parse($from), CarbonImmutable::parse($to), 'MYR', $sellMode);
}

test('a per room plan sends the price for the top occupancy and its restrictions', function () {
    $setup = channelAriSetup();
    channelRate($setup['ratePlan'], $setup['roomType'], '2026-10-14', '150.00', ['min_stay' => 2, 'max_stay' => 7, 'closed_to_arrival' => true]);
    channelRate($setup['ratePlan'], $setup['roomType'], '2026-10-15', '160.00', ['stop_sell' => true, 'closed_to_departure' => true]);

    $values = ariValues($setup, to: '2026-10-15');

    expect($values['2026-10-14'])->toBe(['stop_sell' => false, 'closed_to_arrival' => true, 'closed_to_departure' => false, 'min_stay_arrival' => 2, 'max_stay' => 7, 'rate' => '150.00'])
        ->and($values['2026-10-15'])->toBe(['stop_sell' => true, 'closed_to_arrival' => false, 'closed_to_departure' => true, 'min_stay_arrival' => 1, 'max_stay' => 0, 'rate' => '160.00']);
});

test('a date with no price is sent as stop sell with no rate', function () {
    $setup = channelAriSetup();
    channelRate($setup['ratePlan'], $setup['roomType'], '2026-10-14', null);

    $values = ariValues($setup, to: '2026-10-15');

    expect($values['2026-10-14'])->not->toHaveKey('rate')->and($values['2026-10-14']['stop_sell'])->toBeTrue()
        ->and($values['2026-10-15'])->not->toHaveKey('rate')->and($values['2026-10-15']['stop_sell'])->toBeTrue();
});

test('a per room plan that charges extra adults sends one price for each adult count', function () {
    $setup = channelAriSetup(roomTypeAttributes: ['max_adults' => 3, 'base_occupancy' => 2], planAttributes: ['extra_adult_amount' => '30.00']);
    channelRate($setup['ratePlan'], $setup['roomType'], '2026-10-14', '100.00');

    $day = ariValues($setup, to: '2026-10-14')['2026-10-14'];

    expect($day)->not->toHaveKey('rate')
        ->and($day['rates'])->toBe([
            ['occupancy' => 1, 'rate' => '100.00'],
            ['occupancy' => 2, 'rate' => '100.00'],
            ['occupancy' => 3, 'rate' => '130.00'],
        ]);
});

test('a plan follows the sell mode it was synced with, not what it would be sent as today', function () {
    $setup = channelAriSetup(roomTypeAttributes: ['max_adults' => 3, 'base_occupancy' => 2], planAttributes: ['extra_adult_amount' => '30.00']);
    channelRate($setup['ratePlan'], $setup['roomType'], '2026-10-14', '100.00');

    $day = app(ChannelAriValues::class)->restrictions($setup['ratePlan'], $setup['roomType'], CarbonImmutable::parse('2026-10-14'), CarbonImmutable::parse('2026-10-14'), 'MYR', ChannelSellMode::PerRoom)['2026-10-14'];

    expect($day['rate'])->toBe('130.00')->and($day)->not->toHaveKey('rates');
});

test('a per guest plan sends one price for each adult count', function () {
    $setup = channelAriSetup(state: 'perGuest', roomTypeAttributes: ['max_adults' => 3, 'base_occupancy' => 2]);
    channelRate($setup['ratePlan'], $setup['roomType'], '2026-10-14', '80.00');

    expect(ariValues($setup, to: '2026-10-14')['2026-10-14']['rates'])->toBe([
        ['occupancy' => 1, 'rate' => '80.00'],
        ['occupancy' => 2, 'rate' => '160.00'],
        ['occupancy' => 3, 'rate' => '240.00'],
    ]);
});

test('a derived plan follows its parent price after the derivation', function () {
    $setup = channelAriSetup();
    channelRate($setup['ratePlan'], $setup['roomType'], '2026-10-14', '200.00');
    $derived = channelRatePlan($setup['property'], [$setup['roomType']], 'Member', attributes: [
        'parent_rate_plan_id' => $setup['ratePlan']->id, 'derivation' => RateDerivation::Percent, 'derivation_value' => '-10.00',
    ]);
    channelRate($derived, $setup['roomType'], '2026-10-14', null, ['min_stay' => 3]);

    $values = app(ChannelAriValues::class)->restrictions($derived, $setup['roomType'], CarbonImmutable::parse('2026-10-14'), CarbonImmutable::parse('2026-10-14'), 'MYR');

    expect($values['2026-10-14'])->toMatchArray(['rate' => '180.00', 'min_stay_arrival' => 3, 'stop_sell' => false]);
});

test('a derived plan of a plan that charges extra adults sends each adult count after the derivation', function () {
    $setup = channelAriSetup(roomTypeAttributes: ['max_adults' => 3, 'base_occupancy' => 2], planAttributes: ['extra_adult_amount' => '30.00']);
    channelRate($setup['ratePlan'], $setup['roomType'], '2026-10-14', '200.00');
    $derived = channelRatePlan($setup['property'], [$setup['roomType']], 'Member', attributes: [
        'parent_rate_plan_id' => $setup['ratePlan']->id, 'derivation' => RateDerivation::Percent, 'derivation_value' => '-10.00',
    ]);
    channelRate($derived, $setup['roomType'], '2026-10-14', null);
    $sellMode = app(ChannelCapability::class)->sellMode($derived, $setup['roomType']);

    $values = app(ChannelAriValues::class)->restrictions($derived, $setup['roomType'], CarbonImmutable::parse('2026-10-14'), CarbonImmutable::parse('2026-10-14'), 'MYR', $sellMode);

    expect($sellMode)->toBe(ChannelSellMode::PerPerson)
        ->and($values['2026-10-14']['rates'])->toBe([
            ['occupancy' => 1, 'rate' => '180.00'],
            ['occupancy' => 2, 'rate' => '180.00'],
            ['occupancy' => 3, 'rate' => '207.00'],
        ]);
});

test('a flat package sends its stay price over its nights with min stay equal to max stay', function () {
    $setup = channelAriSetup(state: 'package', planAttributes: ['package_unit' => PackageUnit::PerRoom, 'fixed_nights' => 2]);
    PackagePrice::factory()->create(['rate_plan_id' => $setup['ratePlan']->id, 'room_type_id' => $setup['roomType']->id, 'arrival_from' => '2026-10-01', 'arrival_to' => '2026-10-31', 'amount' => '500.00']);
    foreach ([Weekday::Wednesday, Weekday::Thursday] as $day) {
        $setup['ratePlan']->arrivalWeekdays()->create(['weekday' => $day]);
    }

    $values = ariValues($setup, '2026-10-14', '2026-10-15');

    expect($values['2026-10-14'])->toMatchArray(['rate' => '250.00', 'min_stay_arrival' => 2, 'max_stay' => 2, 'closed_to_arrival' => false])
        ->and($values['2026-10-15']['closed_to_arrival'])->toBeFalse();

    expect(ariValues($setup, '2026-10-16', '2026-10-16')['2026-10-16']['closed_to_arrival'])->toBeTrue();
});

test('availability counts free units and takes off bookings and blocks', function () {
    $setup = channelAriSetup(rooms: 3);

    $values = app(ChannelAriValues::class)->availability([$setup['roomType']], CarbonImmutable::parse('2026-10-14'), CarbonImmutable::parse('2026-10-15'));

    expect($values[$setup['roomType']->id])->toBe(['2026-10-14' => ['availability' => 3], '2026-10-15' => ['availability' => 3]]);
});
