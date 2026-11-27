<?php

use App\Enums\ChargingUnit;
use App\Enums\DefaultRoleEnum;
use App\Enums\TransferDirection;
use App\Enums\TransferMode;
use App\Features\Transfers;
use App\Models\ChargeItem;
use App\Models\Guest;
use App\Models\PropertySetting;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\TransferRoute;
use App\Models\UnitAddOn;
use App\Models\UnitTransfer;
use App\Models\User;
use Database\Factories\BookingSourceFactory;
use Laravel\Pennant\Feature;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * An owner, a property with Transfers on, two rooms of one type with rates for 20 to 22 Oct, a guest, an airport route
 * in with a shared price of 35.00 per guest, and a route out with no price.
 *
 * @return array<string, mixed>
 */
function bookingTransferSetup(): array
{
    $user = User::factory()->withRole(DefaultRoleEnum::Owner)->create();
    $property = $user->property;
    PropertySetting::factory()->for($property)->create();
    Feature::for($property)->activate(Transfers::class);
    $roomType = RoomType::factory()->for($property)->create();
    Room::factory()->for($property)->count(2)->create(['room_type_id' => $roomType->id]);
    $plan = RatePlan::factory()->for($property)->create();
    $plan->roomTypes()->attach($roomType);

    foreach (['2026-10-20', '2026-10-21'] as $date) {
        Rate::factory()->create(['rate_plan_id' => $plan->id, 'room_type_id' => $roomType->id, 'date' => $date, 'amount' => '100.00']);
    }

    $shared = ChargeItem::factory()->for($property)->create(['name' => 'Airport shuttle', 'default_amount' => '35.00', 'charging_unit' => ChargingUnit::PerGuest]);

    return [
        'user' => $user,
        'property' => $property,
        'guest' => Guest::factory()->create(['property_id' => $property->id]),
        'line' => ['room_type_id' => $roomType->id, 'rate_plan_id' => $plan->id, 'adults' => 2, 'child_ages' => []],
        'in' => TransferRoute::factory()->for($property)->create(['name' => 'Airport to lodge', 'shared_charge_item_id' => $shared->id]),
        'out' => TransferRoute::factory()->for($property)->out()->create(['name' => 'Lodge to airport']),
    ];
}

/**
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function bookingTransferBody(array $setup, array $overrides = []): array
{
    return [
        'booking_source_id' => BookingSourceFactory::directFor($setup['property']->id)->id,
        'arrival_date' => '2026-10-20',
        'departure_date' => '2026-10-22',
        'guests' => [['guest_id' => $setup['guest']->id]],
        'billing_mode' => 'master_pays',
        'lines' => [$setup['line'], $setup['line']],
        ...$overrides,
    ];
}

test('the booking form sets the transfer in and out on every room, with the shared charge per guest', function () {
    $setup = bookingTransferSetup();

    $this->actingAs($setup['user'])->post(route('reservations.store', $setup['property']->code), bookingTransferBody($setup, [
        'transfer_in' => ['mode' => 'shared', 'transfer_route_id' => $setup['in']->id, 'pickup_time' => '14:35', 'flight_number' => 'ak 6231', 'bags' => 2],
        'transfer_out' => ['mode' => 'included', 'transfer_route_id' => $setup['out']->id, 'pickup_time' => '11:00'],
    ]))->assertSessionHasNoErrors();

    $units = Reservation::query()->sole()->units()->with('transfers')->get();

    expect($units)->toHaveCount(2);

    foreach ($units as $unit) {
        $in = $unit->transfers->firstWhere('direction', TransferDirection::In);
        $out = $unit->transfers->firstWhere('direction', TransferDirection::Out);

        expect($in->mode)->toBe(TransferMode::Shared)
            ->and($in->pickup_time)->toStartWith('14:35')
            ->and($in->flight_number)->toBe('AK 6231')
            ->and($in->bags)->toBe(2)
            ->and($out->mode)->toBe(TransferMode::Included)
            ->and(UnitAddOn::query()->where('reservation_unit_id', $unit->id)->sole()->quantity)->toBe(2);
    }
});

test('a route of the wrong direction refuses the whole booking on the Transfer in field', function () {
    $setup = bookingTransferSetup();

    $this->actingAs($setup['user'])->post(route('reservations.store', $setup['property']->code), bookingTransferBody($setup, [
        'transfer_in' => ['mode' => 'shared', 'transfer_route_id' => $setup['out']->id, 'pickup_time' => '14:35'],
    ]))->assertSessionHasErrors(['transfer_in' => 'Pick an available route of this direction.']);

    expect(Reservation::query()->exists())->toBeFalse()
        ->and(UnitTransfer::query()->exists())->toBeFalse();
});

test('the transfer fields are checked, and ignored while the Transfers feature is off', function () {
    $setup = bookingTransferSetup();
    $url = route('reservations.store', $setup['property']->code);

    $this->actingAs($setup['user'])->post($url, bookingTransferBody($setup, [
        'transfer_in' => ['mode' => 'shared', 'flight_number' => 'AK-6231'],
    ]))->assertSessionHasErrors(['transfer_in.transfer_route_id', 'transfer_in.pickup_time', 'transfer_in.flight_number']);

    $this->get(route('reservations.new', $setup['property']->code))
        ->assertInertia(fn ($page) => $page->where('transferRoutes.0.name', 'Airport to lodge'));

    Feature::for($setup['property'])->deactivate(Transfers::class);

    $this->post($url, bookingTransferBody($setup, [
        'transfer_in' => ['mode' => 'shared', 'transfer_route_id' => $setup['in']->id, 'pickup_time' => '14:35'],
    ]))->assertSessionHasNoErrors();

    expect(UnitTransfer::query()->exists())->toBeFalse();

    $this->get(route('reservations.new', $setup['property']->code))
        ->assertInertia(fn ($page) => $page->where('transferRoutes', []));
});
