<?php

use App\Data\Meals\MealInclusionData;
use App\Enums\ChargingUnit;
use App\Enums\DefaultRoleEnum;
use App\Enums\InclusionEnd;
use App\Enums\InclusionStart;
use App\Models\ChargeItem;
use App\Models\MealPeriod;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\RatePlan;
use App\Models\RatePlanInclusion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/** @return array{0: User, 1: Property, 2: RatePlan} */
function inclusionSetup(array $planAttributes = []): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create();
    $plan = RatePlan::factory()->for($property)->create($planAttributes);

    return [$owner, $property, $plan];
}

/** @return array<string, mixed> */
function inclusionRow(ChargeItem $chargeItem, array $overrides = []): array
{
    return [
        'id' => null,
        'charge_item_id' => $chargeItem->id,
        'meal_period_id' => null,
        'charging_unit' => 'per_guest_night',
        'quantity' => 1,
        'allocated_amount' => '25',
        'starts_on' => 'day_after_arrival',
        'ends_on' => 'departure_day',
        ...$overrides,
    ];
}

test('the inclusions tab lists the plan inclusions in order with the pickable charge items and meal periods', function () {
    [$owner, $property, $plan] = inclusionSetup();
    $active = ChargeItem::factory()->for($property)->create(['name' => 'Breakfast']);
    $usedButOff = ChargeItem::factory()->for($property)->inactive()->create(['name' => 'Old transfer']);
    ChargeItem::factory()->for($property)->inactive()->create(['name' => 'Unused and off']);
    ChargeItem::factory()->create(['name' => 'Other property']);
    $period = MealPeriod::factory()->for($property)->create(['name' => 'Breakfast']);
    MealPeriod::factory()->for($property)->inactive()->create();
    RatePlanInclusion::factory()->for($plan)->create(['charge_item_id' => $usedButOff->id, 'position' => 1]);
    RatePlanInclusion::factory()->for($plan)->create(['charge_item_id' => $active->id, 'meal_period_id' => $period->id, 'position' => 0]);

    $this->actingAs($owner)
        ->get(route('settings.rate-plans.inclusions.edit', [$property->code, $plan]))
        ->assertInertia(fn (Assert $page) => $page->component('settings/rate-plans/inclusions')
            ->where('inclusions.0.chargeItemId', $active->id)
            ->where('inclusions.0.mealPeriodId', $period->id)
            ->where('inclusions.1.chargeItemId', $usedButOff->id)
            ->has('chargeItems', 2)
            ->where('chargeItems.0.name', 'Breakfast')
            ->where('chargeItems.1.name', 'Old transfer')
            ->has('mealPeriods', 1)
            ->where('mealPeriods.0.name', 'Breakfast')
            ->where('mealPeriods.0.defaultStartsOn', 'day_after_arrival')
            ->where('mealPeriods.0.defaultEndsOn', 'departure_day'));
});

test('inclusions are saved as one ordered list: added, changed, reordered and removed rows', function () {
    [$owner, $property, $plan] = inclusionSetup();
    $breakfast = ChargeItem::factory()->for($property)->create();
    $boat = ChargeItem::factory()->for($property)->create();
    $snorkel = ChargeItem::factory()->for($property)->create();
    $period = MealPeriod::factory()->for($property)->create();
    $first = RatePlanInclusion::factory()->for($plan)->create(['charge_item_id' => $breakfast->id, 'position' => 0]);
    $removed = RatePlanInclusion::factory()->for($plan)->create(['charge_item_id' => $boat->id, 'position' => 1]);

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.inclusions.update', [$property->code, $plan]), ['inclusions' => [
            inclusionRow($snorkel, ['charging_unit' => 'per_guest', 'allocated_amount' => '40.5']),
            inclusionRow($breakfast, ['id' => $first->id, 'meal_period_id' => $period->id, 'quantity' => 2, 'allocated_amount' => '30']),
        ]])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Inclusions saved.']);

    $rows = $plan->inclusions()->get();

    expect($rows->pluck('charge_item_id')->all())->toBe([$snorkel->id, $breakfast->id])
        ->and($rows[0]->charging_unit)->toBe(ChargingUnit::PerGuest)
        ->and($rows[0]->allocated_amount)->toBe('40.50')
        ->and($rows[1]->id)->toBe($first->id)
        ->and($rows[1]->meal_period_id)->toBe($period->id)
        ->and($rows[1]->quantity)->toBe(2)
        ->and($rows[1]->allocated_amount)->toBe('30.00')
        ->and(RatePlanInclusion::query()->whereKey($removed->id)->exists())->toBeFalse()
        ->and(Activity::query()->where('event', 'rate_plan.inclusions_updated')->count())->toBe(1);
});

test('a save that changes nothing is not logged', function () {
    [$owner, $property, $plan] = inclusionSetup();
    $chargeItem = ChargeItem::factory()->for($property)->create();
    $inclusion = RatePlanInclusion::factory()->for($plan)->create([
        'charge_item_id' => $chargeItem->id,
        'allocated_amount' => '25.00',
        'starts_on' => InclusionStart::DayAfterArrival,
        'ends_on' => InclusionEnd::DepartureDay,
    ]);

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.inclusions.update', [$property->code, $plan]), ['inclusions' => [
            inclusionRow($chargeItem, ['id' => $inclusion->id]),
        ]])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Inclusions saved.']);

    expect(Activity::query()->where('event', 'rate_plan.inclusions_updated')->count())->toBe(0);
});

test('an empty list removes every inclusion', function () {
    [$owner, $property, $plan] = inclusionSetup();
    RatePlanInclusion::factory()->for($plan)->create();

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.inclusions.update', [$property->code, $plan]), ['inclusions' => []])
        ->assertSessionHasNoErrors();

    expect($plan->inclusions()->count())->toBe(0);
});

test('inclusions are validated', function (array $row, string $field) {
    [$owner, $property, $plan] = inclusionSetup();
    $chargeItem = ChargeItem::factory()->for($property)->create();

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.inclusions.update', [$property->code, $plan]), ['inclusions' => [inclusionRow($chargeItem, $row)]])
        ->assertSessionHasErrors("inclusions.0.{$field}");

    expect($plan->inclusions()->count())->toBe(0);
})->with([
    'no charge item' => [['charge_item_id' => null], 'charge_item_id'],
    'unknown charging unit' => [['charging_unit' => 'per_hour'], 'charging_unit'],
    'zero quantity' => [['quantity' => 0], 'quantity'],
    'negative amount' => [['allocated_amount' => '-1'], 'allocated_amount'],
    'three decimals' => [['allocated_amount' => '1.005'], 'allocated_amount'],
    'no amount' => [['allocated_amount' => null], 'allocated_amount'],
    'unknown start' => [['starts_on' => 'yesterday'], 'starts_on'],
    'unknown end' => [['ends_on' => 'never'], 'ends_on'],
]);

test('another property\'s charge item or meal period is a validation error', function () {
    [$owner, $property, $plan] = inclusionSetup();
    $own = ChargeItem::factory()->for($property)->create();
    $foreignItem = ChargeItem::factory()->create();
    $foreignPeriod = MealPeriod::factory()->create();

    $url = route('settings.rate-plans.inclusions.update', [$property->code, $plan]);

    $this->actingAs($owner)
        ->put($url, ['inclusions' => [inclusionRow($foreignItem)]])
        ->assertSessionHasErrors('inclusions.0.charge_item_id');
    $this->put($url, ['inclusions' => [inclusionRow($own, ['meal_period_id' => $foreignPeriod->id])]])
        ->assertSessionHasErrors('inclusions.0.meal_period_id');

    expect($plan->inclusions()->count())->toBe(0);
});

test('an archived plan is refused with an error toast and nothing is logged', function () {
    [$owner, $property, $plan] = inclusionSetup(['archived_at' => now()]);
    $chargeItem = ChargeItem::factory()->for($property)->create();

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.inclusions.update', [$property->code, $plan]), ['inclusions' => [inclusionRow($chargeItem)]])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The rate plan, charge items or meal periods changed. Reload the page and try again.']);

    expect($plan->inclusions()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'rate_plan.inclusions_updated')->count())->toBe(0);
});

test('an inactive charge item or meal period is refused unless the plan already uses it', function () {
    [$owner, $property, $plan] = inclusionSetup();
    $off = ChargeItem::factory()->for($property)->inactive()->create();
    $offPeriod = MealPeriod::factory()->for($property)->inactive()->create();
    $on = ChargeItem::factory()->for($property)->create();
    $url = route('settings.rate-plans.inclusions.update', [$property->code, $plan]);

    $this->actingAs($owner)->put($url, ['inclusions' => [inclusionRow($off)]])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The rate plan, charge items or meal periods changed. Reload the page and try again.']);
    $this->put($url, ['inclusions' => [inclusionRow($on, ['meal_period_id' => $offPeriod->id])]])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The rate plan, charge items or meal periods changed. Reload the page and try again.']);

    expect($plan->inclusions()->count())->toBe(0);

    $kept = RatePlanInclusion::factory()->for($plan)->create(['charge_item_id' => $off->id, 'meal_period_id' => $offPeriod->id]);

    $this->put($url, ['inclusions' => [inclusionRow($off, ['id' => $kept->id, 'meal_period_id' => $offPeriod->id, 'allocated_amount' => '30'])]])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Inclusions saved.']);

    expect($kept->refresh()->allocated_amount)->toBe('30.00');
});

test('an inclusion id from another plan is refused and changes nothing', function () {
    [$owner, $property, $plan] = inclusionSetup();
    $chargeItem = ChargeItem::factory()->for($property)->create();
    $otherPlan = RatePlan::factory()->for($property)->create();
    $foreign = RatePlanInclusion::factory()->for($otherPlan)->create(['charge_item_id' => $chargeItem->id, 'allocated_amount' => '10.00']);

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.inclusions.update', [$property->code, $plan]), ['inclusions' => [
            inclusionRow($chargeItem, ['id' => $foreign->id, 'allocated_amount' => '99']),
        ]])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The rate plan, charge items or meal periods changed. Reload the page and try again.']);

    expect($foreign->refresh()->allocated_amount)->toBe('10.00')
        ->and($plan->inclusions()->count())->toBe(0);
});

test('inclusions need settings.manage and stay inside the property', function () {
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    $code = $frontDesk->property->code;
    $plan = RatePlan::factory()->for($frontDesk->property)->create();

    $this->actingAs($frontDesk)->get(route('settings.rate-plans.inclusions.edit', [$code, $plan]))->assertForbidden();
    $this->put(route('settings.rate-plans.inclusions.update', [$code, $plan]), ['inclusions' => []])->assertForbidden();

    [$owner, $property] = inclusionSetup();
    $this->actingAs($owner)
        ->get(route('settings.rate-plans.inclusions.edit', [$property->code, $plan]))
        ->assertNotFound();
});

test('the database refuses a zero quantity and an unknown charging unit', function () {
    $plan = RatePlan::factory()->create();

    expect(fn () => RatePlanInclusion::factory()->for($plan)->create(['quantity' => 0]))->toThrow(QueryException::class);
    expect(fn () => RatePlanInclusion::factory()->for($plan)->create(['allocated_amount' => '-1']))->toThrow(QueryException::class);
});

test('a meal inclusion becomes the input of the meal entitlement resolver', function () {
    $plan = RatePlan::factory()->create();
    $period = MealPeriod::factory()->for($plan->property)->create(['starts_at' => '07:00']);
    $inclusion = RatePlanInclusion::factory()->for($plan)->create([
        'charge_item_id' => ChargeItem::factory()->for($plan->property)->create()->id,
        'meal_period_id' => $period->id,
        'starts_on' => InclusionStart::ArrivalDay,
        'ends_on' => InclusionEnd::NightBeforeDeparture,
    ]);

    $data = MealInclusionData::fromInclusion($inclusion->load('mealPeriod'));

    expect($data->mealPeriodId)->toBe($period->id)
        ->and($data->mealPeriodStartsAt)->toBe('07:00:00')
        ->and($data->startsOn)->toBe(InclusionStart::ArrivalDay)
        ->and($data->endsOn)->toBe(InclusionEnd::NightBeforeDeparture);
});
