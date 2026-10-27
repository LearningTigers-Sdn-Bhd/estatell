<?php

use App\Enums\DefaultRoleEnum;
use App\Enums\RateDerivation;
use App\Models\PropertySetting;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Rates\RateCalendarService;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;

test('saved prices hold only the plan, its room types and every date of the range', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create();
    $plan = RatePlan::factory()->for($property)->create(['name' => 'Basic']);
    $other = RatePlan::factory()->for($property)->create(['name' => 'Other']);
    $deluxe = RoomType::factory()->for($property)->create(['name' => 'Deluxe']);
    $suite = RoomType::factory()->for($property)->create(['name' => 'Suite']);
    $plan->roomTypes()->attach($deluxe);
    $other->roomTypes()->attach([$deluxe->id, $suite->id]);
    Rate::factory()->create(['room_type_id' => $deluxe->id, 'rate_plan_id' => $plan->id, 'date' => '2026-10-14', 'amount' => '200.00']);
    Rate::factory()->create(['room_type_id' => $deluxe->id, 'rate_plan_id' => $other->id, 'date' => '2026-10-14', 'amount' => '999.00']);

    $saved = app(RateCalendarService::class)->forPlan($property, $plan, Date::parse('2026-10-14'), Date::parse('2026-10-16'), 'MYR');

    expect($saved->dates)->toBe(['2026-10-14', '2026-10-15', '2026-10-16'])
        ->and($saved->roomTypes)->toHaveCount(1)
        ->and($saved->roomTypes[0]->name)->toBe('Deluxe')
        ->and($saved->roomTypes[0]->plans)->toHaveCount(1)
        ->and($saved->roomTypes[0]->plans[0]->id)->toBe($plan->id)
        ->and($saved->roomTypes[0]->plans[0]->cells)->toHaveCount(1)
        ->and($saved->roomTypes[0]->plans[0]->cells[0]->amount)->toBe('200.00')
        ->and($saved->roomTypes[0]->availability)->toBe([]);
});

test('saved prices of a derived plan show the parent price after the derivation', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create();
    $parent = RatePlan::factory()->for($property)->create(['name' => 'Basic']);
    $roomType = RoomType::factory()->for($property)->create();
    $parent->roomTypes()->attach($roomType);
    $derived = RatePlan::factory()->for($property)->create([
        'name' => 'Promo', 'pricing_basis' => $parent->pricing_basis, 'parent_rate_plan_id' => $parent->id,
        'derivation' => RateDerivation::Percent, 'derivation_value' => '-10',
    ]);
    $derived->roomTypes()->attach($roomType);
    Rate::factory()->create(['room_type_id' => $roomType->id, 'rate_plan_id' => $parent->id, 'date' => '2026-10-14', 'amount' => '200.00']);

    $plan = app(RateCalendarService::class)->forPlan($property, $derived, Date::parse('2026-10-14'), Date::parse('2026-10-14'), 'MYR')->roomTypes[0]->plans[0];

    expect($plan->derived)->toBeTrue()
        ->and($plan->followsName)->toBe('Basic')
        ->and($plan->cells[0]->amount)->toBe('180.00');
});

test('the saved prices tab sends the plan, the range and how far each room type is priced', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create();
    $plan = RatePlan::factory()->for($property)->create();
    $roomType = RoomType::factory()->for($property)->create();
    $plan->roomTypes()->attach($roomType);
    Rate::factory()->create(['room_type_id' => $roomType->id, 'rate_plan_id' => $plan->id, 'date' => '2026-10-14', 'amount' => '200.00']);

    $this->actingAs($owner)->get(route('settings.rate-plans.saved-prices.show', [$property->code, $plan, 'from' => '2026-10-14', 'to' => '2026-10-20']))
        ->assertInertia(fn (Assert $page) => $page->component('settings/rate-plans/saved-prices')
            ->where('ratePlan.id', $plan->id)
            ->where('saved.from', '2026-10-14')
            ->where('saved.to', '2026-10-20')
            ->has('saved.dates', 7)
            ->where('saved.roomTypes.0.plans.0.cells.0.amount', '200.00')
            ->where('pricedUntil.'.$roomType->id, '2026-10-14')
            ->has('weekendDays'));

    $this->actingAs($owner)->get(route('settings.rate-plans.saved-prices.show', [$property->code, $plan, 'from' => '2026-10-14', 'to' => '2026-12-31']))
        ->assertSessionHasErrors('to');
});

test('the saved prices tab is limited to settings managers of the property', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create();
    $plan = RatePlan::factory()->for($property)->create();
    $otherOwner = User::factory()->owner()->create();
    $otherPlan = RatePlan::factory()->for($otherOwner->property)->create();
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create(['property_id' => $property->id]);

    $this->actingAs($frontDesk)->get(route('settings.rate-plans.saved-prices.show', [$property->code, $plan]))->assertForbidden();
    $this->actingAs($owner)->get(route('settings.rate-plans.saved-prices.show', [$property->code, $otherPlan]))->assertNotFound();
});
