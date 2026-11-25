<?php

use App\Enums\UnitStatus;
use App\Features\Pos;
use App\Models\BusinessDate;
use App\Models\ChargeItem;
use App\Models\MealPeriod;
use App\Models\PosOutlet;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\RatePlanInclusion;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Pennant\Feature;

/**
 * A live property with POS integration on, an open business date, a CheckedIn unit, a staff user with every POS
 * permission, and an outlet whose charge item carries an inclusive 8% tax.
 *
 * @return array{property: Property, actor: User, unit: ReservationUnit, outlet: PosOutlet, item: ChargeItem}
 */
function posSetup(bool $inclusiveTax = true): array
{
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);
    Feature::for($property)->activate(Pos::class);

    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $unit = ReservationUnit::factory()->checkedIn()->create(['reservation_id' => $reservation->id]);
    $item = ChargeItem::factory()->create(['property_id' => $property->id, 'default_amount' => null, 'allow_override' => true]);
    $tax = Tax::factory()->for($property)->create(['rate' => '8.00', 'is_inclusive' => $inclusiveTax]);
    $tax->applicableTransactionCodes()->attach($item->transactionCode);
    $outlet = PosOutlet::factory()->create(['property_id' => $property->id, 'default_charge_item_id' => $item->id]);
    $actor = User::factory()->for($property)->withPermissions(['pos.view', 'pos.manage', 'pos.resolve', 'meals.redeem'])->create();

    return ['property' => $property, 'actor' => $actor, 'unit' => $unit, 'outlet' => $outlet, 'item' => $item];
}

function posUnitStatus(ReservationUnit $unit, UnitStatus $status): void
{
    $unit->forceFill(['status' => $status])->save();
}

/**
 * posSetup() plus a meal period on the unit's rate plan and a unit of 3 adults in house 13 to 16 October, so 14 October
 * is a full day with breakfast.
 *
 * @return array<string, mixed>
 */
function mealSetup(): array
{
    $setup = posSetup();
    $setup['unit']->forceFill(['arrival_date' => '2026-10-13', 'departure_date' => '2026-10-16', 'adults' => 3, 'children' => 0])->save();
    $period = MealPeriod::factory()->create(['property_id' => $setup['property']->id, 'name' => 'Breakfast', 'starts_at' => '07:00', 'ends_at' => '10:00']);
    RatePlanInclusion::factory()->create(['rate_plan_id' => $setup['unit']->rate_plan_id, 'meal_period_id' => $period->id]);

    return [...$setup, 'period' => $period, 'date' => CarbonImmutable::parse('2026-10-14')];
}
