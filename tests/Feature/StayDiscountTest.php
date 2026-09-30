<?php

use App\Enums\DefaultRoleEnum;
use App\Enums\PricingBasis;
use App\Enums\StayDiscountType;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\RatePlan;
use App\Models\RatePlanStayDiscount;
use App\Models\User;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/** @return array{0: User, 1: Property, 2: RatePlan} */
function discountSetup(array $planAttributes = []): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create();
    $plan = RatePlan::factory()->for($property)->create($planAttributes);

    return [$owner, $property, $plan];
}

test('the discounts tab lists the plan rules by minimum nights', function () {
    [$owner, $property, $plan] = discountSetup();
    RatePlanStayDiscount::factory()->for($plan)->create(['min_nights' => 7, 'value' => '25.00']);
    RatePlanStayDiscount::factory()->for($plan)->create(['min_nights' => 3, 'value' => '10.00']);

    $this->actingAs($owner)
        ->get(route('settings.rate-plans.discounts.edit', [$property->code, $plan]))
        ->assertInertia(fn (Assert $page) => $page->component('settings/rate-plans/discounts')
            ->where('discounts.0.minNights', 3)
            ->where('discounts.1.minNights', 7)
            ->where('discounts.1.value', '25.00'));
});

test('discounts are saved as one list: added, changed and removed rows', function () {
    [$owner, $property, $plan] = discountSetup();
    RatePlanStayDiscount::factory()->for($plan)->create(['min_nights' => 3, 'value' => '10.00']);
    RatePlanStayDiscount::factory()->for($plan)->create(['min_nights' => 5, 'value' => '15.00']);

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.discounts.update', [$property->code, $plan]), ['discounts' => [
            ['min_nights' => 3, 'discount_type' => 'percent', 'value' => '12', 'from_night' => 2],
            ['min_nights' => 7, 'discount_type' => 'amount', 'value' => '30', 'from_night' => 1],
        ]])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Discounts saved.']);

    $rules = $plan->stayDiscounts()->orderBy('min_nights')->get();

    expect($rules->pluck('min_nights')->all())->toBe([3, 7])
        ->and($rules[0]->value)->toBe('12.00')
        ->and($rules[0]->from_night)->toBe(2)
        ->and($rules[1]->discount_type)->toBe(StayDiscountType::Amount)
        ->and(Activity::query()->where('event', 'rate_plan.stay_discounts_updated')->count())->toBe(1);
});

test('a save that changes nothing is not logged', function () {
    [$owner, $property, $plan] = discountSetup();
    RatePlanStayDiscount::factory()->for($plan)->create(['min_nights' => 3, 'value' => '10.00']);

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.discounts.update', [$property->code, $plan]), ['discounts' => [
            ['min_nights' => 3, 'discount_type' => 'percent', 'value' => '10', 'from_night' => 1],
        ]])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Discounts saved.']);

    expect(Activity::query()->where('event', 'rate_plan.stay_discounts_updated')->count())->toBe(0);
});

test('an empty list removes every discount', function () {
    [$owner, $property, $plan] = discountSetup();
    RatePlanStayDiscount::factory()->for($plan)->create();

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.discounts.update', [$property->code, $plan]), ['discounts' => []])
        ->assertSessionHasNoErrors();

    expect($plan->stayDiscounts()->count())->toBe(0);
});

test('discounts are validated', function (array $row, string $field) {
    [$owner, $property, $plan] = discountSetup();
    $valid = ['min_nights' => 3, 'discount_type' => 'percent', 'value' => '10', 'from_night' => 1];

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.discounts.update', [$property->code, $plan]), ['discounts' => [[...$valid, ...$row]]])
        ->assertSessionHasErrors("discounts.0.{$field}");

    expect($plan->stayDiscounts()->count())->toBe(0);
})->with([
    'one night' => [['min_nights' => 1], 'min_nights'],
    'percent above 100' => [['value' => '101'], 'value'],
    'percent of zero' => [['value' => '0'], 'value'],
    'amount of zero' => [['discount_type' => 'amount', 'value' => '0'], 'value'],
    'unknown type' => [['discount_type' => 'free'], 'discount_type'],
    'from night past the minimum' => [['from_night' => 4], 'from_night'],
    'from night zero' => [['from_night' => 0], 'from_night'],
]);

test('two rules cannot share the same minimum nights', function () {
    [$owner, $property, $plan] = discountSetup();
    $rule = ['discount_type' => 'percent', 'value' => '10', 'from_night' => 1];

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.discounts.update', [$property->code, $plan]), ['discounts' => [
            ['min_nights' => 3, ...$rule],
            ['min_nights' => 3, ...$rule],
        ]])
        ->assertSessionHasErrors('discounts.0.min_nights');
});

test('an archived plan is refused with an error toast and nothing is logged', function () {
    [$owner, $property, $plan] = discountSetup(['archived_at' => now()]);

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.discounts.update', [$property->code, $plan]), ['discounts' => [
            ['min_nights' => 3, 'discount_type' => 'percent', 'value' => '10', 'from_night' => 1],
        ]])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The rate plan changed. Reload the page and try again.']);

    expect($plan->stayDiscounts()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'rate_plan.stay_discounts_updated')->count())->toBe(0);
});

test('package plans have no discounts tab', function () {
    [$owner, $property, $plan] = discountSetup(['pricing_basis' => PricingBasis::Package]);

    $this->actingAs($owner)
        ->get(route('settings.rate-plans.discounts.edit', [$property->code, $plan]))
        ->assertNotFound();
    $this->put(route('settings.rate-plans.discounts.update', [$property->code, $plan]), ['discounts' => []])
        ->assertNotFound();
});

test('discounts need settings.manage and stay inside the property', function () {
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    $code = $frontDesk->property->code;
    $plan = RatePlan::factory()->for($frontDesk->property)->create();

    $this->actingAs($frontDesk)->get(route('settings.rate-plans.discounts.edit', [$code, $plan]))->assertForbidden();
    $this->put(route('settings.rate-plans.discounts.update', [$code, $plan]), ['discounts' => []])->assertForbidden();

    [$owner, $property] = discountSetup();
    $this->actingAs($owner)
        ->get(route('settings.rate-plans.discounts.edit', [$property->code, $plan]))
        ->assertNotFound();
});

test('the database refuses a from night past the minimum nights', function () {
    $plan = RatePlan::factory()->create();

    expect(fn () => RatePlanStayDiscount::factory()->for($plan)->create(['min_nights' => 3, 'from_night' => 4]))
        ->toThrow(QueryException::class);
});
