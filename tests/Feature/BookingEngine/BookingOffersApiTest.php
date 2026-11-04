<?php

require_once __DIR__.'/../Support/BookingApiSetup.php';

use App\Enums\PolicyScope;
use App\Enums\SystemTransactionCode;
use App\Models\BookingClient;
use App\Models\ChargeItem;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\RoomBlock;
use App\Models\RoomRevenuePolicy;
use App\Models\Tax;

/**
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $body
 * @return array<string, mixed>
 */
function bookingRoomOffer(array $setup, array $body = []): array
{
    $offers = bookingApiPost('properties/'.$setup['property']->code.'/offers', bookingApiStay($body))->assertOk()->json();

    return collect($offers)->first(fn (array $offer): bool => $offer['room_type']['id'] === $setup['roomType']->id && $offer['rate_plan']['id'] === $setup['plan']->id);
}

it('splits a Malaysian and a foreign guest as the docs show', function () {
    $setup = bookingApiProperty();
    bookingMicrositeClient();
    bookingApiSetRate($setup['plan']->id, $setup['roomType']->id, '200.00');
    bookingApiTaxes($setup['property'], $setup['roomCode']);

    expect(bookingRoomOffer($setup, ['nationality' => 'MY']))
        ->pay_online->toBe(['subtotal' => '400.00', 'taxes' => [['name' => 'SST 8%', 'amount' => '32.00', 'inclusive' => false]], 'total' => '432.00'])
        ->pay_at_property->toBe(['taxes' => [], 'total' => '0.00'])
        ->currency->toBe('MYR')
        ->blocked->toBeNull();

    expect(bookingRoomOffer($setup, ['nationality' => 'sg']))
        ->pay_online->toBe(['subtotal' => '400.00', 'taxes' => [['name' => 'SST 8%', 'amount' => '32.00', 'inclusive' => false]], 'total' => '432.00'])
        ->pay_at_property->toBe(['taxes' => [['name' => 'Tourism tax', 'amount' => '20.00', 'inclusive' => false]], 'total' => '20.00']);
});

it('prices a guest with no nationality as a local guest', function () {
    $setup = bookingApiProperty();
    bookingMicrositeClient();
    bookingApiTaxes($setup['property'], $setup['roomCode']);

    expect(bookingRoomOffer($setup)['pay_at_property']['total'])->toBe('0.00');
});

it('does not charge an inclusive flagged tax twice', function () {
    $setup = bookingApiProperty();
    bookingMicrositeClient();
    $tax = Tax::factory()->for($setup['property'])->create(['name' => 'Levy', 'rate_type' => 'flat', 'rate' => '10.00', 'is_inclusive' => true, 'collect_at_check_in' => true]);
    $tax->applicableTransactionCodes()->attach($setup['roomCode']);

    expect(bookingRoomOffer($setup))
        ->pay_online->toBe(['subtotal' => '180.00', 'taxes' => [], 'total' => '180.00'])
        ->pay_at_property->toBe(['taxes' => [['name' => 'Levy', 'amount' => '20.00', 'inclusive' => true]], 'total' => '20.00']);
});

it('adds auto items, and takes a flagged tax on an add-on online', function () {
    $setup = bookingApiProperty();
    bookingMicrositeClient();
    $item = ChargeItem::factory()->for($setup['property'])->create(['name' => 'Linen', 'auto_add' => true, 'default_amount' => '15.00', 'charging_unit' => 'per_stay']);
    $tax = Tax::factory()->for($setup['property'])->create(['name' => 'Levy', 'rate_type' => 'flat', 'rate' => '1.00', 'collect_at_check_in' => true]);
    $tax->applicableTransactionCodes()->attach($item->transaction_code_id);

    expect(bookingRoomOffer($setup))
        ->pay_online->toBe(['subtotal' => '215.00', 'taxes' => [['name' => 'Levy', 'amount' => '1.00', 'inclusive' => false]], 'total' => '216.00'])
        ->pay_at_property->toBe(['taxes' => [], 'total' => '0.00']);
});

it('shows only public plans that are switched on', function () {
    $setup = bookingApiProperty();
    bookingMicrositeClient();
    $setup['bedPlan']->forceFill(['bookable_online' => false])->save();
    $company = RatePlan::factory()->for($setup['property'])->create(['company_access' => 'all']);
    $company->forceFill(['bookable_online' => true])->save();
    $company->roomTypes()->attach($setup['roomType']);

    $offers = bookingApiPost('properties/'.$setup['property']->code.'/offers', bookingApiStay())->assertOk()->json();

    expect(collect($offers)->pluck('rate_plan.id')->all())->toBe([$setup['plan']->id]);
});

it('prices a bed for one adult, whatever the party', function () {
    $setup = bookingApiProperty();
    bookingMicrositeClient();

    $offers = bookingApiPost('properties/'.$setup['property']->code.'/offers', bookingApiStay(['adults' => 40]))->assertOk()->json();
    $bed = collect($offers)->firstWhere('room_type.id', $setup['dorm']->id);

    expect($bed)
        ->rooms_left->toBe(3)
        ->blocked->toBeNull()
        ->and($bed['pay_online']['total'])->toBe('80.00');
});

it('blocks an offer with a stable code', function (Closure $change, string $code) {
    $setup = bookingApiProperty();
    bookingMicrositeClient();
    $body = $change($setup) ?? [];

    expect(bookingRoomOffer($setup, $body))
        ->pay_online->toBeNull()
        ->pay_at_property->toBeNull()
        ->and(bookingRoomOffer($setup, $body)['blocked']['code'])->toBe($code)
        ->and(bookingRoomOffer($setup, $body)['blocked']['message'])->not->toBeEmpty();
})->with([
    'too many adults' => [fn (array $setup) => ['adults' => 40], 'too_many_adults'],
    'too many children' => [fn (array $setup) => ['child_ages' => array_fill(0, 40, 5)], 'too_many_children'],
    'no rate' => [fn (array $setup) => Rate::query()->where('rate_plan_id', $setup['plan']->id)->delete() ? [] : [], 'rate_missing'],
    'stop sell' => [fn (array $setup) => Rate::query()->where('rate_plan_id', $setup['plan']->id)->update(['stop_sell' => true]) ? [] : [], 'restricted'],
    'no rooms left' => [function (array $setup) {
        foreach ($setup['rooms'] as $room) {
            RoomBlock::factory()->for($setup['property'])->create(['room_id' => $room->id, 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-22']);
        }

        return [];
    }, 'not_available'],
    'no room charge code' => [fn (array $setup) => $setup['roomCode']->delete() ? [] : [], 'pricing_unavailable'],
]);

it('returns the live cancellation terms', function () {
    $setup = bookingApiProperty();
    bookingMicrositeClient();
    RoomRevenuePolicy::factory()->for($setup['property'])->fixed('75.00')->forCode(SystemTransactionCode::CancellationRevenue, PolicyScope::WholeStay)->create(['free_until_days' => 3, 'text_en' => 'Free until 3 days']);

    expect(bookingRoomOffer($setup)['cancellation_terms'])
        ->amount->toBe('75.00')
        ->free_until_days->toBe(3)
        ->text_en->toBe('Free until 3 days');
});

it('refuses an arrival outside the booking window', function (string $arrival, string $departure) {
    $setup = bookingApiProperty();
    bookingMicrositeClient();

    bookingApiPost('properties/'.$setup['property']->code.'/offers', bookingApiStay(['arrival' => $arrival, 'departure' => $departure]))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'outside_window');
})->with([
    'yesterday' => ['2026-10-13', '2026-10-15'],
    'past 6 months' => ['2027-04-15', '2027-04-16'],
]);

it('allows an arrival on the last date of the window', function () {
    $setup = bookingApiProperty();
    bookingMicrositeClient();

    bookingApiPost('properties/'.$setup['property']->code.'/offers', bookingApiStay(['arrival' => '2027-04-14', 'departure' => '2027-04-15']))->assertOk();
});

it('validates the stay', function (array $body, string $field) {
    $setup = bookingApiProperty();
    bookingMicrositeClient();

    bookingApiPost('properties/'.$setup['property']->code.'/offers', bookingApiStay($body))->assertJsonValidationErrors($field);
})->with([
    'departure not after arrival' => [['departure' => '2026-10-20'], 'departure'],
    'no adults' => [['adults' => 0], 'adults'],
    'child age too high' => [['child_ages' => [18]], 'child_ages.0'],
    'bad nationality' => [['nationality' => 'Malaysia'], 'nationality'],
]);

it('shows the lowest price and closed dates on the calendar', function () {
    $setup = bookingApiProperty();
    bookingMicrositeClient();
    Rate::query()->where('rate_plan_id', $setup['bedPlan']->id)->whereDate('date', '2026-10-21')->update(['stop_sell' => true]);
    Rate::query()->where('rate_plan_id', $setup['plan']->id)->whereDate('date', '2026-10-21')->update(['stop_sell' => true]);
    Rate::query()->where('rate_plan_id', $setup['bedPlan']->id)->whereDate('date', '2026-10-22')->update(['stop_sell' => true]);

    bookingApiGet('properties/'.$setup['property']->code.'/calendar?from=2026-10-20&to=2026-10-22')->assertOk()->assertExactJson([
        ['date' => '2026-10-20', 'lowest_price' => '40.00', 'closed' => false],
        ['date' => '2026-10-21', 'lowest_price' => null, 'closed' => true],
        ['date' => '2026-10-22', 'lowest_price' => '100.00', 'closed' => false],
    ]);
});

it('closes calendar dates with no price, no rooms, or outside the window', function () {
    $setup = bookingApiProperty();
    bookingMicrositeClient();
    Rate::factory()->create(['rate_plan_id' => $setup['plan']->id, 'room_type_id' => $setup['roomType']->id, 'date' => '2026-10-13', 'amount' => '100.00']);
    $setup['bedPlan']->forceFill(['bookable_online' => false])->save();

    foreach ($setup['rooms'] as $room) {
        RoomBlock::factory()->for($setup['property'])->create(['room_id' => $room->id, 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-21']);
    }

    $days = bookingApiGet('properties/'.$setup['property']->code.'/calendar?from=2026-10-13&to=2026-10-20')->assertOk()->json();

    expect(collect($days)->every(fn (array $day): bool => $day['closed'] && $day['lowest_price'] === null))->toBeTrue();
});

it('limits the calendar to 62 days', function () {
    $setup = bookingApiProperty();
    bookingMicrositeClient();
    $path = 'properties/'.$setup['property']->code.'/calendar?from=2026-10-14&to=';

    bookingApiGet($path.'2026-12-14')->assertOk()->assertJsonCount(62);
    bookingApiGet($path.'2026-12-15')->assertJsonValidationErrors('to');
});

it('searches the lowest online price per property, leaving out those with nothing to sell', function () {
    $first = bookingApiProperty();
    $second = bookingApiProperty('palm-beach');
    Rate::query()->where('rate_plan_id', $second['plan']->id)->delete();
    Rate::query()->where('rate_plan_id', $second['bedPlan']->id)->delete();
    bookingMicrositeClient([BookingClient::ABILITY_SEARCH]);

    bookingApiPost('search', bookingApiStay())->assertOk()->assertExactJson([[
        'code' => $first['property']->code,
        'slug' => 'sand-bay',
        'name' => $first['property']->name,
        'city' => 'Semporna',
        'currency' => 'MYR',
        'lowest_price' => '80.00',
    ]]);
});
