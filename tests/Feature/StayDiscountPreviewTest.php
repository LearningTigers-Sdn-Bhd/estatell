<?php

use App\Enums\AgeBandPricing;
use App\Enums\DefaultRoleEnum;
use App\Enums\PricingBasis;
use App\Models\Property;
use App\Models\PropertyAgeGroup;
use App\Models\PropertySetting;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\RatePlanChildPrice;
use App\Models\RatePlanStayDiscount;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array{0: User, 1: Property, 2: RatePlan, 3: RoomType} */
function previewSetup(): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create();
    $plan = RatePlan::factory()->for($property)->create(['pricing_basis' => PricingBasis::PerGuest]);
    $roomType = RoomType::factory()->for($property)->create(['name' => 'Standard Twin']);
    $plan->roomTypes()->attach($roomType);

    return [$owner, $property, $plan, $roomType];
}

function previewRates(RatePlan $plan, RoomType $roomType, string $amount, string ...$dates): void
{
    foreach ($dates as $date) {
        Rate::factory()->create(['rate_plan_id' => $plan->id, 'room_type_id' => $roomType->id, 'date' => $date, 'amount' => $amount]);
    }
}

/**
 * @param  array<string, mixed>  $payload
 */
function previewPost(User $owner, Property $property, RatePlan $plan, array $payload): TestResponse
{
    return test()->actingAs($owner)->postJson(
        route('settings.rate-plans.discounts.preview', [$property->code, $plan]),
        [...['discounts' => [], 'arrival' => '2026-11-02'], ...$payload],
    );
}

const RESORT_RULE = ['min_nights' => 2, 'discount_type' => 'amount', 'value' => '100', 'from_night' => 2];

test('the preview prices each room type with unsaved rules, per guest per night', function () {
    [$owner, $property, $plan, $roomType] = previewSetup();
    previewRates($plan, $roomType, '475.00', '2026-11-02', '2026-11-03');

    previewPost($owner, $property, $plan, ['discounts' => [RESORT_RULE]])
        ->assertOk()
        ->assertJsonPath('stayLengths', [1, 2])
        ->assertJsonPath('ignoredRules', 0)
        ->assertJsonPath('rows.0.name', 'Standard Twin')
        ->assertJsonPath('rows.0.adults', 2)
        ->assertJsonPath('rows.0.unit', 'guest')
        ->assertJsonPath('rows.0.stays.0.total', '950.00')
        ->assertJsonPath('rows.0.stays.1.total', '1700.00')
        ->assertJsonPath('rows.0.stays.1.discount', '200.00')
        ->assertJsonPath('rows.0.stays.1.nightAmounts', ['475.00', '375.00']);

    expect($plan->stayDiscounts()->count())->toBe(0);
});

test('the by night view prices each night on its own, then the total', function () {
    [$owner, $property, $plan, $roomType] = previewSetup();
    previewRates($plan, $roomType, '475.00', '2026-11-02', '2026-11-03', '2026-11-04');

    previewPost($owner, $property, $plan, ['discounts' => [RESORT_RULE]])
        ->assertOk()
        ->assertJsonPath('nights', 2)
        ->assertJsonCount(2, 'rows.0.byNight.nights');

    previewPost($owner, $property, $plan, ['discounts' => [RESORT_RULE], 'nights' => 3])
        ->assertOk()
        ->assertJsonPath('nights', 3)
        ->assertJsonPath('stayLengths', [1, 2])
        ->assertJsonPath('rows.0.byNight.nights.0', ['amount' => '950.00', 'discount' => '0.00', 'unitAmount' => '475.00'])
        ->assertJsonPath('rows.0.byNight.nights.2', ['amount' => '750.00', 'discount' => '200.00', 'unitAmount' => '375.00'])
        ->assertJsonPath('rows.0.byNight.total', '2450.00')
        ->assertJsonPath('rows.0.byNight.discount', '400.00');
});

test('rules that are not valid yet are left out and counted', function () {
    [$owner, $property, $plan, $roomType] = previewSetup();
    previewRates($plan, $roomType, '475.00', '2026-11-02', '2026-11-03');

    previewPost($owner, $property, $plan, ['discounts' => [
        RESORT_RULE,
        ['min_nights' => '', 'discount_type' => 'percent', 'value' => '', 'from_night' => '1'],
        ['min_nights' => 3, 'discount_type' => 'percent', 'value' => '150', 'from_night' => 1],
    ]])
        ->assertOk()
        ->assertJsonPath('stayLengths', [1, 2])
        ->assertJsonPath('ignoredRules', 2);
});

test('a room type without a rate shows the missing date', function () {
    [$owner, $property, $plan, $roomType] = previewSetup();
    previewRates($plan, $roomType, '475.00', '2026-11-02');

    previewPost($owner, $property, $plan, ['discounts' => [RESORT_RULE]])
        ->assertOk()
        ->assertJsonPath('rows.0.refusal', 'No rate on 3 Nov 2026.')
        ->assertJsonPath('rows.0.stays', []);
});

test('a custom party with children is priced per room, and a party that does not fit is refused', function () {
    [$owner, $property, $plan, $roomType] = previewSetup();
    $child = PropertyAgeGroup::factory()->for($property)->create(['min_age' => 6, 'max_age' => 11]);
    RatePlanChildPrice::factory()->for($plan)->create([
        'property_age_group_id' => $child->id,
        'pricing' => AgeBandPricing::Fixed,
        'value' => '200.00',
    ]);
    previewRates($plan, $roomType, '475.00', '2026-11-02', '2026-11-03');

    previewPost($owner, $property, $plan, [
        'discounts' => [RESORT_RULE],
        'adults' => 2,
        'children' => [['age_group_id' => $child->id, 'count' => 1]],
    ])
        ->assertOk()
        ->assertJsonPath('rows.0.unit', 'room')
        ->assertJsonPath('rows.0.children', 1)
        ->assertJsonPath('rows.0.stays.1.nightAmounts', ['1150.00', '850.00']);

    previewPost($owner, $property, $plan, ['adults' => 3])
        ->assertOk()
        ->assertJsonPath('rows.0.refusal', 'Too many guests for this room type.');
});

test('the discounts tab opens with a preview of the saved rules from today', function () {
    Date::setTestNow('2026-11-02 09:00:00');
    [$owner, $property, $plan, $roomType] = previewSetup();
    RatePlanStayDiscount::factory()->for($plan)->create(['min_nights' => 2, 'discount_type' => 'amount', 'value' => '100.00', 'from_night' => 2]);
    previewRates($plan, $roomType, '475.00', '2026-11-02', '2026-11-03');

    $this->actingAs($owner)
        ->get(route('settings.rate-plans.discounts.edit', [$property->code, $plan]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('preview.arrival', '2026-11-02')
            ->where('preview.rows.0.stays.1.total', '1700.00'));
});

test('the preview needs settings.manage, stays inside the property and is not for package plans', function () {
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    $plan = RatePlan::factory()->for($frontDesk->property)->create();

    previewPost($frontDesk, $frontDesk->property, $plan, [])->assertForbidden();

    [$owner, $property] = previewSetup();
    previewPost($owner, $property, $plan, [])->assertNotFound();

    $package = RatePlan::factory()->for($property)->create(['pricing_basis' => PricingBasis::Package]);
    previewPost($owner, $property, $package, [])->assertNotFound();
});
