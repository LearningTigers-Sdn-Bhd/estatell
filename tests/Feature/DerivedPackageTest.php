<?php

use App\Actions\Rates\CreateDerivedRatePlan;
use App\Actions\Rates\SavePackagePricing;
use App\Actions\Rates\SaveRatePlanDerivation;
use App\Actions\Rates\UpdateRatePlan;
use App\Enums\PackageUnit;
use App\Enums\PricingBasis;
use App\Enums\RateDerivation;
use App\Models\PackagePrice;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Rates\DerivedRatePlans;
use App\Services\Rates\RateCalendarService;
use App\Services\Rates\RateQuoteRequest;
use App\Services\Rates\RateQuoteService;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/**
 * A package parent "Dive Package" (2 nights, per guest, RM600 for arrivals in June 2026) on one room type.
 *
 * @return array{0: User, 1: Property, 2: RatePlan, 3: RoomType}
 */
function derivedPackageSetup(PackageUnit $unit = PackageUnit::PerGuest, int $nights = 2): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create();
    $parent = RatePlan::factory()->for($property)->package($unit, $nights)->create(['name' => 'Dive Package']);
    $roomType = RoomType::factory()->for($property)->create(['name' => 'Deluxe']);
    $parent->roomTypes()->attach($roomType);
    PackagePrice::factory()->for($parent)->create(['room_type_id' => $roomType->id, 'arrival_from' => '2026-06-01', 'arrival_to' => '2026-06-30', 'amount' => '600.00']);

    return [$owner, $property, $parent, $roomType];
}

function derivedPackage(Property $property, RatePlan $parent, RoomType $roomType, string $value = '-10', array $attributes = []): RatePlan
{
    $plan = RatePlan::factory()->for($property)->package($parent->package_unit, $parent->fixed_nights)->create([
        'name' => 'Dive Package - Petronas',
        'parent_rate_plan_id' => $parent->id,
        'derivation' => RateDerivation::Percent,
        'derivation_value' => $value,
        ...$attributes,
    ]);
    $plan->roomTypes()->attach($roomType);

    return $plan;
}

test('a package can follow a package with the same unit and nights only', function () {
    [$owner, $property, $parent, $roomType] = derivedPackageSetup();
    $child = RatePlan::factory()->for($property)->package(PackageUnit::PerGuest, 2)->create(['name' => 'Child']);
    $child->roomTypes()->attach($roomType);
    $otherNights = RatePlan::factory()->for($property)->package(PackageUnit::PerGuest, 3)->create();
    $otherNights->roomTypes()->attach($roomType);
    $otherUnit = RatePlan::factory()->for($property)->package(PackageUnit::PerRoom, 2)->create();
    $otherUnit->roomTypes()->attach($roomType);
    $nightly = RatePlan::factory()->for($property)->create();
    $nightly->roomTypes()->attach($roomType);

    $service = app(DerivedRatePlans::class);

    expect($service->eligibleParents($child)->pluck('id')->all())->toBe([$parent->id])
        ->and($service->eligibleParents($nightly)->pluck('id')->all())->toBe([])
        ->and($service->cannotFollowReason($child))->toBeNull();
});

test('a package follows a package by a percent and drops its own prices', function () {
    [$owner, $property, $parent, $roomType] = derivedPackageSetup();
    $child = RatePlan::factory()->for($property)->package()->create(['name' => 'Child']);
    $child->roomTypes()->attach($roomType);
    PackagePrice::factory()->for($child)->create(['room_type_id' => $roomType->id, 'arrival_from' => '2026-06-01', 'arrival_to' => '2026-06-30', 'amount' => '500.00']);

    expect(app(SaveRatePlanDerivation::class)($owner, $child, $parent->id, RateDerivation::Percent, '-10'))->toBeTrue()
        ->and($child->refresh()->parent_rate_plan_id)->toBe($parent->id)
        ->and($child->packagePrices()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'rate_plan.derivation_saved')->sole()->properties['own_prices_cleared'])->toBe(1);
});

test('a package cannot follow by an amount', function () {
    [$owner, $property, $parent, $roomType] = derivedPackageSetup();
    $child = RatePlan::factory()->for($property)->package()->create();
    $child->roomTypes()->attach($roomType);

    expect(app(SaveRatePlanDerivation::class)($owner, $child, $parent->id, RateDerivation::Amount, '-50'))->toBeFalse()
        ->and($child->refresh()->parent_rate_plan_id)->toBeNull();
});

test('a derived package quote is the parent package price after the percent', function () {
    [$owner, $property, $parent, $roomType] = derivedPackageSetup();
    $child = derivedPackage($property, $parent, $roomType);
    $arrival = Date::parse('2026-06-10');

    $quote = app(RateQuoteService::class)->quote(new RateQuoteRequest($child->fresh(), $roomType, $arrival, $arrival->addDays(2), 2));

    expect(array_map(fn ($night): string => (string) $night->total()->getAmount(), $quote->nights))->toBe(['540.00', '540.00']);
});

test('a derived package refuses a stay of another length like its parent', function () {
    [$owner, $property, $parent, $roomType] = derivedPackageSetup();
    $child = derivedPackage($property, $parent, $roomType);
    $arrival = Date::parse('2026-06-10');

    $quote = app(RateQuoteService::class)->quote(new RateQuoteRequest($child->fresh(), $roomType, $arrival, $arrival->addDays(3), 2));

    expect($quote->refusal)->toBe('stay_length_mismatch');
});

test('the quick action makes a derived package that copies the nights and unit', function () {
    [$owner, $property, $parent, $roomType] = derivedPackageSetup(PackageUnit::PerRoom, 3);

    $plan = app(CreateDerivedRatePlan::class)($owner, $parent, 'Dive Package - Petronas', RateDerivation::Percent, '-10', [$roomType->id]);

    expect($plan->pricing_basis)->toBe(PricingBasis::Package)
        ->and($plan->fixed_nights)->toBe(3)
        ->and($plan->package_unit)->toBe(PackageUnit::PerRoom)
        ->and($plan->parent_rate_plan_id)->toBe($parent->id);
});

test('the quick action refuses an amount on a package parent', function () {
    [$owner, $property, $parent, $roomType] = derivedPackageSetup();

    expect(app(CreateDerivedRatePlan::class)($owner, $parent, 'Dive Package - Petronas', RateDerivation::Amount, '-50', [$roomType->id]))->toBeNull()
        ->and(RatePlan::query()->where('property_id', $property->id)->count())->toBe(1);

    $this->actingAs($owner)
        ->post(route('settings.rate-plans.derived.store', [$property->code, $parent]), ['name' => 'X', 'derivation' => 'amount', 'direction' => 'down', 'value' => '50', 'room_type_ids' => [$roomType->id]])
        ->assertSessionHasErrors('derivation');
});

test('the derivation form refuses an amount on a package', function () {
    [$owner, $property, $parent, $roomType] = derivedPackageSetup();
    $child = RatePlan::factory()->for($property)->package()->create();
    $child->roomTypes()->attach($roomType);

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.pricing.derivation.update', [$property->code, $child]), ['parent_rate_plan_id' => $parent->id, 'derivation' => 'amount', 'direction' => 'down', 'value' => '50'])
        ->assertSessionHasErrors('derivation');

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.pricing.derivation.update', [$property->code, $child]), ['parent_rate_plan_id' => $parent->id, 'derivation' => 'percent', 'direction' => 'down', 'value' => '10'])
        ->assertSessionHasNoErrors();

    expect($child->refresh()->derivation_value)->toBe('-10.00');
});

test('the preview shows the package price of the period that holds today', function () {
    [$owner, $property, $parent, $roomType] = derivedPackageSetup();
    $this->travelTo('2026-06-10 09:00:00');

    $this->actingAs($owner)
        ->postJson(route('settings.rate-plans.derived.preview', [$property->code, $parent]), ['parent_rate_plan_id' => $parent->id, 'derivation' => 'percent', 'direction' => 'down', 'value' => '10'])
        ->assertOk()
        ->assertJsonPath('0.parentAmount', '600.00')
        ->assertJsonPath('0.derivedAmount', '540.00');

    $this->travelTo('2026-08-01 09:00:00');

    $this->actingAs($owner)
        ->postJson(route('settings.rate-plans.derived.preview', [$property->code, $parent]), ['parent_rate_plan_id' => $parent->id, 'derivation' => 'percent', 'direction' => 'down', 'value' => '10'])
        ->assertJsonPath('0.parentAmount', null);
});

test('the nights of a package are locked while it follows or is followed', function () {
    [$owner, $property, $parent, $roomType] = derivedPackageSetup();
    $child = derivedPackage($property, $parent, $roomType);
    $free = RatePlan::factory()->for($property)->package(PackageUnit::PerGuest, 4)->create(['name' => 'Free']);

    $put = fn (RatePlan $plan, int $nights) => $this->actingAs($owner)
        ->put(route('settings.rate-plans.update', [$property->code, $plan]), ['name' => $plan->name, 'fixed_nights' => $nights]);

    $put($parent, 5)->assertSessionHasErrors('fixed_nights');
    $put($child, 5)->assertSessionHasErrors('fixed_nights');
    $put($child, 2)->assertSessionHasNoErrors();
    $put($free, 5)->assertSessionHasNoErrors();

    expect($parent->refresh()->fixed_nights)->toBe(2)
        ->and($child->refresh()->fixed_nights)->toBe(2)
        ->and($free->refresh()->fixed_nights)->toBe(5);
});

test('the action refuses to change the nights of a followed package', function () {
    [$owner, $property, $parent, $roomType] = derivedPackageSetup();
    derivedPackage($property, $parent, $roomType);

    expect(app(UpdateRatePlan::class)($owner, $parent, ['name' => 'Dive Package', 'fixed_nights' => 5]))->toBeFalse()
        ->and($parent->refresh()->fixed_nights)->toBe(2);
});

test('a derived package saves its arrival weekdays but takes no prices', function () {
    [$owner, $property, $parent, $roomType] = derivedPackageSetup();
    $child = derivedPackage($property, $parent, $roomType);
    $price = ['id' => null, 'room_type_id' => $roomType->id, 'arrival_from' => '2026-06-01', 'arrival_to' => '2026-06-30', 'amount' => '500.00'];

    expect(app(SavePackagePricing::class)($owner, $child, [$price], ['friday']))->toBeFalse()
        ->and(app(SavePackagePricing::class)($owner, $child, [], ['friday', 'saturday']))->toBeTrue()
        ->and($child->arrivalWeekdays()->count())->toBe(2)
        ->and($child->packagePrices()->count())->toBe(0);
});

test('the rate calendar shows the parent package price after the percent', function () {
    [$owner, $property, $parent, $roomType] = derivedPackageSetup();
    $child = derivedPackage($property, $parent, $roomType);
    $from = Date::parse('2026-06-10');

    $calendar = app(RateCalendarService::class)->forRange($property, $from, $from->addDays(2), $from, 'MYR');
    $rows = collect($calendar->roomTypes[0]->plans)->keyBy('id');

    expect($rows[$parent->id]->cells[0]->amount)->toBe('600.00')
        ->and($rows[$child->id]->cells[0]->amount)->toBe('540.00');
});

test('the pricing tab of a package sends the follow options', function () {
    [$owner, $property, $parent, $roomType] = derivedPackageSetup();
    $child = RatePlan::factory()->for($property)->package()->create(['name' => 'Child']);
    $child->roomTypes()->attach($roomType);

    $this->actingAs($owner)->get(route('settings.rate-plans.pricing.edit', [$property->code, $child]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/rate-plans/package-pricing')
            ->has('derivation.parents', 1)
            ->where('derivation.parents.0.id', $parent->id)
            ->has('derivations'));
});
