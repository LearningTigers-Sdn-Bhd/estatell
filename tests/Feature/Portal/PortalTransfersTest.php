<?php

use App\Enums\ChargingUnit;
use App\Enums\SystemTransactionCode;
use App\Enums\TransferDirection;
use App\Enums\TransferMode;
use App\Features\Transfers;
use App\Models\ChargeItem;
use App\Models\Reservation;
use App\Models\TransactionCode;
use App\Models\TransferRoute;
use App\Models\UnitAddOn;
use App\Models\UnitTransfer;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

require_once __DIR__.'/../Support/PortalBookingSetup.php';

/**
 * A portal company at a property with Transfers on: a route in with a private price of 120.00 per stay, and a route
 * out with no price.
 *
 * @return array<string, mixed>
 */
function portalTransferSetup(): array
{
    Notification::fake();
    $setup = portalBookingSetup();
    Feature::for($setup['property'])->activate(Transfers::class);
    TransactionCode::factory()->for($setup['property'])->system(SystemTransactionCode::RoomRevenue)->create();
    $setup['van'] = ChargeItem::factory()->for($setup['property'])->create(['name' => 'Private van', 'default_amount' => '120.00', 'charging_unit' => ChargingUnit::PerStay]);
    $setup['in'] = TransferRoute::factory()->for($setup['property'])->create(['name' => 'Airport to lodge', 'private_charge_item_id' => $setup['van']->id]);
    $setup['out'] = TransferRoute::factory()->for($setup['property'])->out()->create(['name' => 'Lodge to airport']);

    return $setup;
}

test('the agent sets the road transfers when booking, for every room, and the private van is charged per room', function () {
    $setup = portalTransferSetup();

    $this->actingAs($setup['portalUser'])->get(route('portal.bookings.create', $setup['property']))
        ->assertInertia(fn (Assert $page) => $page->has('transferRoutes', 2));

    portalBook($setup, portalBookingBody($setup, [portalRoomLine($setup, ['rooms' => 2])], [
        'expected_total' => '400.00',
        'transfer_in' => ['mode' => 'private', 'transfer_route_id' => $setup['in']->id, 'pickup_time' => '09:30', 'flight_number' => 'MH 2610'],
        'transfer_out' => ['mode' => 'included', 'transfer_route_id' => $setup['out']->id, 'pickup_time' => '12:00'],
    ]))->assertSessionHasNoErrors();

    $units = Reservation::query()->sole()->units()->get();

    expect($units)->toHaveCount(2)
        ->and(UnitTransfer::query()->where('direction', TransferDirection::In)->where('mode', TransferMode::Private)->count())->toBe(2)
        ->and(UnitTransfer::query()->where('direction', TransferDirection::Out)->count())->toBe(2)
        ->and(UnitAddOn::query()->where('charge_item_id', $setup['van']->id)->count())->toBe(2);
});

test('a route that is turned off refuses the booking with a message for the agent, and Transfers off ignores the picks', function () {
    $setup = portalTransferSetup();
    $setup['in']->update(['active' => false]);
    $body = portalBookingBody($setup, [portalRoomLine($setup)], [
        'transfer_in' => ['mode' => 'private', 'transfer_route_id' => $setup['in']->id, 'pickup_time' => '09:30'],
    ]);

    portalBook($setup, $body)->assertSessionHasErrors(['form' => 'This transfer route is no longer offered. Pick another or leave it out.']);
    expect(Reservation::query()->exists())->toBeFalse();

    Feature::for($setup['property'])->deactivate(Transfers::class);

    $this->actingAs($setup['portalUser'])->get(route('portal.bookings.create', $setup['property']))
        ->assertInertia(fn (Assert $page) => $page->where('transferRoutes', []));
    portalBook($setup, $body)->assertSessionHasNoErrors();

    expect(UnitTransfer::query()->exists())->toBeFalse();
});
