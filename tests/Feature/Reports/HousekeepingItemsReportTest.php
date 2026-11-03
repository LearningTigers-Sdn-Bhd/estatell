<?php

use App\Data\Reports\HousekeepingItemRow;
use App\Data\Reports\ReportFilters;
use App\Enums\ChargingUnit;
use App\Enums\UnitStatus;
use App\Models\ChargeItem;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\UnitAddOn;
use App\Services\Reports\HousekeepingItemsReport;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/ReportSetup.php';

/**
 * A unit staying 20 to 23 Oct with 2 adults, and an add-on of `$item` on it.
 */
function housekeepingItemUnit(Property $property, ChargeItem $item, ?string $serviceDate = null, UnitStatus $status = UnitStatus::Confirmed): ReservationUnit
{
    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $unit = ReservationUnit::factory()->for($reservation)->create([
        'property_id' => $property->id,
        'arrival_date' => '2026-10-20',
        'departure_date' => '2026-10-23',
        'adults' => 2,
    ]);
    $unit->forceFill(['status' => $status])->save();
    (new UnitAddOn)->forceFill(['reservation_unit_id' => $unit->id, 'charge_item_id' => $item->id, 'quantity' => 1, 'unit_amount' => '50.00', 'service_date' => $serviceDate])->save();

    return $unit;
}

/**
 * @return list<array{0: string, 1: int, 2: string}>
 */
function housekeepingItemsOn(Property $property, string $date): array
{
    return array_map(
        fn (HousekeepingItemRow $row): array => [$row->item, $row->quantity, $row->action],
        app(HousekeepingItemsReport::class)->rows($property, new ReportFilters(from: $date, to: $date)),
    );
}

test('an item for the whole stay is set up on arrival, in the room after, and removed on departure', function () {
    [, $property] = reportSetup();
    $bed = ChargeItem::factory()->for($property)->create(['name' => 'Extra bed', 'charging_unit' => ChargingUnit::PerStay, 'housekeeping_item' => true]);
    housekeepingItemUnit($property, $bed);

    expect(housekeepingItemsOn($property, '2026-10-19'))->toBe([])
        ->and(housekeepingItemsOn($property, '2026-10-20'))->toBe([['Extra bed', 1, 'Set up']])
        ->and(housekeepingItemsOn($property, '2026-10-22'))->toBe([['Extra bed', 1, 'In room']])
        ->and(housekeepingItemsOn($property, '2026-10-23'))->toBe([['Extra bed', 1, 'Remove']])
        ->and(housekeepingItemsOn($property, '2026-10-24'))->toBe([]);
});

test('a nightly item follows its own nights', function () {
    [, $property] = reportSetup();
    $bed = ChargeItem::factory()->for($property)->create(['name' => 'Extra bed', 'charging_unit' => ChargingUnit::PerNight, 'housekeeping_item' => true]);
    housekeepingItemUnit($property, $bed, '2026-10-21');

    expect(housekeepingItemsOn($property, '2026-10-20'))->toBe([])
        ->and(housekeepingItemsOn($property, '2026-10-21'))->toBe([['Extra bed', 1, 'Set up']])
        ->and(housekeepingItemsOn($property, '2026-10-22'))->toBe([['Extra bed', 1, 'Remove']]);
});

test('items not shown to housekeeping and cancelled rooms are left out', function () {
    [, $property] = reportSetup();
    $bed = ChargeItem::factory()->for($property)->create(['name' => 'Extra bed', 'charging_unit' => ChargingUnit::PerStay, 'housekeeping_item' => true]);
    $parking = ChargeItem::factory()->for($property)->create(['name' => 'Parking', 'charging_unit' => ChargingUnit::PerStay]);
    housekeepingItemUnit($property, $parking);
    housekeepingItemUnit($property, $bed, status: UnitStatus::Cancelled);

    expect(housekeepingItemsOn($property, '2026-10-20'))->toBe([]);
});

test('the page lists the items of the date and is in the reports menu', function () {
    [$manager, $property] = reportSetup();
    $bed = ChargeItem::factory()->for($property)->create(['name' => 'Extra bed', 'charging_unit' => ChargingUnit::PerStay, 'housekeeping_item' => true]);
    housekeepingItemUnit($property, $bed);

    $this->actingAs($manager)
        ->get("/p/{$property->code}/reports/housekeeping-items?date=2026-10-20")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('reports/housekeeping-items')
            ->where('rows.data.0.item', 'Extra bed')
            ->where('rows.data.0.action', 'Set up')
            ->where('totals.figures.quantity', 1));
});
