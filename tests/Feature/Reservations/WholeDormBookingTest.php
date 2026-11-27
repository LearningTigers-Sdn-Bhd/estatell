<?php

require_once __DIR__.'/../Support/BookingSetup.php';

use App\Models\Bed;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Services\Availability\AvailabilityService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * A booking form payload with one whole-dorm line for the setup's dorm D1 (three beds), 20–22 Oct.
 *
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $line
 * @return array<string, mixed>
 */
function wholeDormBody(array $setup, array $line = []): array
{
    return [
        'booking_source_id' => $setup['phone']->id,
        'arrival_date' => '2026-10-20',
        'departure_date' => '2026-10-22',
        'guests' => [['guest_id' => $setup['guest']->id]],
        'billing_mode' => 'master_pays',
        'lines' => [['room_type_id' => $setup['dorm']->id, 'rate_plan_id' => $setup['bedPlan']->id, 'adults' => 1, 'beds' => 1, 'whole_room_id' => $setup['beds'][0]->room_id, ...$line]],
    ];
}

test('a whole dorm books every bed of the dorm and splits the dorm price over them', function () {
    $setup = bookingSetup();

    $this->actingAs($setup['user'])
        ->post(route('reservations.store', ['property' => $setup['property']->code]), wholeDormBody($setup, ['override_total' => '200.00', 'override_reason' => 'Whole dorm price']))
        ->assertSessionHasNoErrors();

    $units = Reservation::query()->sole()->units()->orderBy('id')->get();

    expect($units)->toHaveCount(3)
        ->and($units->pluck('whole_room_id')->unique()->all())->toBe([$setup['beds'][0]->room_id])
        ->and($units->map(fn (ReservationUnit $unit) => $unit->nights()->pluck('bed_id')->unique()->sole())->all())->toBe(array_map(fn ($bed) => $bed->id, $setup['beds']))
        ->and($units->map(fn (ReservationUnit $unit) => (string) $unit->nights()->sum('amount'))->all())->toBe(['66.67', '66.67', '66.66'])
        ->and(app(AvailabilityService::class)->availableForStay($setup['dorm'], CarbonImmutable::parse('2026-10-20'), CarbonImmutable::parse('2026-10-22')))->toBe(0);
});

test('a whole dorm without a price override is priced per bed', function () {
    $setup = bookingSetup();

    $this->actingAs($setup['user'])
        ->post(route('reservations.store', ['property' => $setup['property']->code]), wholeDormBody($setup))
        ->assertSessionHasNoErrors();

    expect((string) Reservation::query()->sole()->units()->get()->sum(fn (ReservationUnit $unit) => $unit->nights()->sum('amount')))->toBe('240');
});

test('a whole dorm is refused while one bed of it is booked, and nothing is saved', function () {
    $setup = bookingSetup();
    $this->actingAs($setup['user'])->post(route('reservations.store', ['property' => $setup['property']->code]), [
        ...wholeDormBody($setup),
        'arrival_date' => '2026-10-21',
        'departure_date' => '2026-10-23',
        'lines' => [['room_type_id' => $setup['dorm']->id, 'rate_plan_id' => $setup['bedPlan']->id, 'adults' => 1, 'beds' => 1, 'bed_ids' => [$setup['beds'][1]->id]]],
    ])->assertSessionHasNoErrors();

    $this->actingAs($setup['user'])
        ->post(route('reservations.store', ['property' => $setup['property']->code]), wholeDormBody($setup))
        ->assertSessionHasErrors(['lines.0.whole_room_id' => 'A whole dorm can be booked only when every bed is free on every night. Pick another dorm or other dates.']);

    expect(Reservation::query()->count())->toBe(1);
});

test('a whole dorm must be a dorm of the line room type', function () {
    $setup = bookingSetup();

    $this->actingAs($setup['user'])
        ->post(route('reservations.store', ['property' => $setup['property']->code]), wholeDormBody($setup, ['whole_room_id' => $setup['rooms'][0]->id]))
        ->assertSessionHasErrors(['lines.0.whole_room_id' => 'Pick a dorm of this room type.']);

    expect(Reservation::query()->count())->toBe(0);
});

test('the free units list offers only the dorms whose every bed is free', function () {
    $setup = bookingSetup();
    $second = Room::factory()->for($setup['property'])->create(['room_type_id' => $setup['dorm']->id, 'number' => 'D2']);
    $secondBed = Bed::factory()->create(['room_id' => $second->id, 'label' => 'Bed 1']);
    $this->actingAs($setup['user'])->post(route('reservations.store', ['property' => $setup['property']->code]), [
        ...wholeDormBody($setup),
        'lines' => [['room_type_id' => $setup['dorm']->id, 'rate_plan_id' => $setup['bedPlan']->id, 'adults' => 1, 'beds' => 1, 'bed_ids' => [$setup['beds'][2]->id]]],
    ])->assertSessionHasNoErrors();

    $this->actingAs($setup['user'])
        ->getJson(route('reservations.free-units', ['property' => $setup['property']->code, 'room_type_id' => $setup['dorm']->id, 'arrival_date' => '2026-10-20', 'departure_date' => '2026-10-22']))
        ->assertOk()
        ->assertJsonPath('wholeRooms', [['id' => $second->id, 'label' => 'D2', 'bedIds' => [$secondBed->id]]]);
});

test('the quote of a whole dorm takes the override as the dorm total', function () {
    $setup = bookingSetup();

    $this->actingAs($setup['user'])
        ->postJson(route('reservations.quote', ['property' => $setup['property']->code]), [
            'room_type_id' => $setup['dorm']->id,
            'rate_plan_id' => $setup['bedPlan']->id,
            'arrival_date' => '2026-10-20',
            'departure_date' => '2026-10-22',
            'adults' => 1,
            'beds' => 3,
            'whole_room' => true,
            'override_total' => '200.00',
        ])
        ->assertOk()
        ->assertJsonPath('lineTotal', '200.00')
        ->assertJsonPath('unitTotal', '66.67');
});
