<?php

use App\Actions\Rates\ArchiveRatePlan;
use App\Actions\Rates\CopyParentStayDiscounts;
use App\Actions\Rates\ExtendRates;
use App\Actions\Rates\RestoreRatePlan;
use App\Actions\Rates\SaveRatePlanDerivation;
use App\Actions\Rates\SaveRates;
use App\Actions\Rates\SyncRatePlanRoomTypes;
use App\Data\Rates\RateRestrictionsData;
use App\Enums\PricingBasis;
use App\Enums\RateDerivation;
use App\Enums\StayDiscountType;
use App\Models\Property;
use App\Models\PropertyAgeGroup;
use App\Models\PropertySetting;
use App\Models\Rate;
use App\Models\RateOccupancyPrice;
use App\Models\RatePlan;
use App\Models\RatePlanChildPrice;
use App\Models\RatePlanStayDiscount;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Rates\RateQuoteRequest;
use App\Services\Rates\RateQuoteService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/** @return array{0: User, 1: Property, 2: RatePlan, 3: RoomType} */
function derivedSetup(array $parentAttributes = []): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create();
    $parent = RatePlan::factory()->for($property)->create(['name' => 'Basic Rate', ...$parentAttributes]);
    $roomType = RoomType::factory()->for($property)->create(['name' => 'Deluxe']);
    $parent->roomTypes()->attach($roomType);

    return [$owner, $property, $parent, $roomType];
}

function derivedPlan(Property $property, RatePlan $parent, RoomType $roomType, RateDerivation $derivation, string $value, array $attributes = []): RatePlan
{
    $plan = RatePlan::factory()->for($property)->create([
        'name' => 'Breakfast',
        'pricing_basis' => $parent->pricing_basis,
        'parent_rate_plan_id' => $parent->id,
        'derivation' => $derivation,
        'derivation_value' => $value,
        ...$attributes,
    ]);
    $plan->roomTypes()->attach($roomType);

    return $plan;
}

function derivedParentRate(RatePlan $plan, RoomType $roomType, string $date, string $amount = '200.00'): Rate
{
    return Rate::factory()->create(['rate_plan_id' => $plan->id, 'room_type_id' => $roomType->id, 'date' => $date, 'amount' => $amount]);
}

function derivedQuote(RatePlan $plan, RoomType $roomType, int $adults = 2, array $childAges = [], string $departure = '2026-11-03'): string
{
    $quote = app(RateQuoteService::class)->quote(new RateQuoteRequest($plan->fresh(), $roomType, Date::parse('2026-11-02'), Date::parse($departure), $adults, $childAges));

    return $quote->isRefused() ? 'refused:'.$quote->refusal : (string) $quote->total->getAmount();
}

test('a derived plan prices as its parent adjusted by a percent or an amount', function (RateDerivation $derivation, string $value, string $expected) {
    [, $property, $parent, $roomType] = derivedSetup();
    derivedParentRate($parent, $roomType, '2026-11-02', '200.00');
    $plan = derivedPlan($property, $parent, $roomType, $derivation, $value);

    expect(derivedQuote($plan, $roomType))->toBe($expected);
})->with([
    'ten percent off' => [RateDerivation::Percent, '-10.00', '180.00'],
    'fifty more' => [RateDerivation::Amount, '50.00', '250.00'],
    'twenty off' => [RateDerivation::Amount, '-20.00', '180.00'],
    'never below zero' => [RateDerivation::Amount, '-500.00', '0.00'],
]);

test('a derived plan is refused when the parent has no rate or is archived', function () {
    [, $property, $parent, $roomType] = derivedSetup();
    $plan = derivedPlan($property, $parent, $roomType, RateDerivation::Percent, '-10.00');

    expect(derivedQuote($plan, $roomType))->toBe('refused:rate_missing');

    derivedParentRate($parent, $roomType, '2026-11-02');
    $parent->forceFill(['archived_at' => now()])->save();

    expect(derivedQuote($plan, $roomType))->toBe('refused:plan_archived');
});

test('a parent price change carries through to the derived plan', function () {
    [, $property, $parent, $roomType] = derivedSetup();
    $rate = derivedParentRate($parent, $roomType, '2026-11-02', '200.00');
    $plan = derivedPlan($property, $parent, $roomType, RateDerivation::Percent, '-10.00');

    expect(derivedQuote($plan, $roomType))->toBe('180.00');

    $rate->update(['amount' => '220.00']);

    expect(derivedQuote($plan, $roomType))->toBe('198.00');
});

test('a derived per guest plan adds an amount per guest and scales child prices with a percent', function () {
    [, $property, $parent, $roomType] = derivedSetup(['pricing_basis' => PricingBasis::PerGuest]);
    $group = PropertyAgeGroup::factory()->for($property)->create(['min_age' => 4, 'max_age' => 11]);
    RatePlanChildPrice::factory()->create(['rate_plan_id' => $parent->id, 'property_age_group_id' => $group->id, 'pricing' => 'fixed', 'value' => '40.00']);
    derivedParentRate($parent, $roomType, '2026-11-02', '100.00');

    $amount = derivedPlan($property, $parent, $roomType, RateDerivation::Amount, '10.00', ['name' => 'Plus ten']);
    $percent = derivedPlan($property, $parent, $roomType, RateDerivation::Percent, '-50.00', ['name' => 'Half']);

    expect(derivedQuote($amount, $roomType, 2, [6]))->toBe('270.00')
        ->and(derivedQuote($percent, $roomType, 2, [6]))->toBe('120.00');
});

test('a derived plan takes long-stay discounts from its own rules, not the parent', function () {
    [, $property, $parent, $roomType] = derivedSetup();
    derivedParentRate($parent, $roomType, '2026-11-02', '200.00');
    derivedParentRate($parent, $roomType, '2026-11-03', '200.00');
    RatePlanStayDiscount::factory()->create(['rate_plan_id' => $parent->id, 'min_nights' => 2, 'discount_type' => StayDiscountType::Percent, 'value' => '50.00', 'from_night' => 1]);
    $plan = derivedPlan($property, $parent, $roomType, RateDerivation::Amount, '-50.00');

    expect(derivedQuote($plan, $roomType, departure: '2026-11-04'))->toBe('300.00');

    RatePlanStayDiscount::factory()->create(['rate_plan_id' => $plan->id, 'min_nights' => 2, 'discount_type' => StayDiscountType::Percent, 'value' => '10.00', 'from_night' => 1]);

    expect(derivedQuote($plan, $roomType, departure: '2026-11-04'))->toBe('270.00');
});

test('saving a derivation links the parent, clears own prices and keeps restrictions', function () {
    [$owner, $property, $parent, $roomType] = derivedSetup();
    $child = RatePlan::factory()->for($property)->create(['name' => 'Corporate']);
    $child->roomTypes()->attach($roomType);
    $rate = Rate::factory()->create(['rate_plan_id' => $child->id, 'room_type_id' => $roomType->id, 'date' => '2026-11-02', 'amount' => '150.00', 'min_stay' => 2]);
    RateOccupancyPrice::factory()->create(['rate_id' => $rate->id, 'adults' => 1, 'amount' => '120.00']);

    $saved = app(SaveRatePlanDerivation::class)($owner, $child, $parent->id, RateDerivation::Percent, '-15');

    expect($saved)->toBeTrue()
        ->and($child->fresh()->parent_rate_plan_id)->toBe($parent->id)
        ->and($child->fresh()->derivation_value)->toBe('-15.00')
        ->and($rate->fresh()->amount)->toBeNull()
        ->and($rate->fresh()->min_stay)->toBe(2)
        ->and($rate->occupancyPrices()->count())->toBe(0);

    $log = Activity::query()->where('event', 'rate_plan.derivation_saved')->sole();
    expect($log->properties['own_prices_cleared'])->toBe(1);
});

test('saving the same derivation again writes and logs nothing', function () {
    [$owner, $property, $parent, $roomType] = derivedSetup();
    $child = derivedPlan($property, $parent, $roomType, RateDerivation::Percent, '-10.00');

    expect(app(SaveRatePlanDerivation::class)($owner, $child, $parent->id, RateDerivation::Percent, '-10'))->toBeTrue()
        ->and(Activity::query()->where('event', 'rate_plan.derivation_saved')->count())->toBe(0);
});

test('a plan can stop following and then has no prices', function () {
    [$owner, $property, $parent, $roomType] = derivedSetup();
    $child = derivedPlan($property, $parent, $roomType, RateDerivation::Percent, '-10.00');

    expect(app(SaveRatePlanDerivation::class)($owner, $child, null))->toBeTrue()
        ->and($child->fresh()->parent_rate_plan_id)->toBeNull()
        ->and($child->fresh()->derivation)->toBeNull()
        ->and(Activity::query()->where('event', 'rate_plan.derivation_removed')->count())->toBe(1);
});

test('a plan cannot follow an ineligible parent', function (string $case) {
    [$owner, $property, $parent, $roomType] = derivedSetup();
    $child = RatePlan::factory()->for($property)->create(['name' => 'Corporate']);
    $child->roomTypes()->attach($roomType);

    $parentId = match ($case) {
        'itself' => $child->id,
        'archived' => RatePlan::factory()->for($property)->archived()->create()->id,
        'another property' => RatePlan::factory()->create()->id,
        'a derived plan' => derivedPlan($property, $parent, $roomType, RateDerivation::Percent, '-5.00', ['name' => 'Chain'])->id,
        'a package plan' => RatePlan::factory()->for($property)->package()->create()->id,
        'another pricing basis' => tap(RatePlan::factory()->for($property)->perGuest()->create(), fn (RatePlan $plan) => $plan->roomTypes()->attach($roomType))->id,
        'a plan missing a room type' => RatePlan::factory()->for($property)->create()->id,
    };

    expect(app(SaveRatePlanDerivation::class)($owner, $child, $parentId, RateDerivation::Percent, '-10'))->toBeFalse()
        ->and($child->fresh()->parent_rate_plan_id)->toBeNull();
})->with(['itself', 'archived', 'another property', 'a derived plan', 'a package plan', 'another pricing basis', 'a plan missing a room type']);

test('a plan that others follow cannot follow, and an archived plan cannot change', function () {
    [$owner, $property, $parent, $roomType] = derivedSetup();
    $other = RatePlan::factory()->for($property)->create(['name' => 'Other']);
    $other->roomTypes()->attach($roomType);
    derivedPlan($property, $parent, $roomType, RateDerivation::Percent, '-10.00');
    $archived = RatePlan::factory()->for($property)->archived()->create();

    expect(app(SaveRatePlanDerivation::class)($owner, $parent, $other->id, RateDerivation::Percent, '-10'))->toBeFalse()
        ->and(app(SaveRatePlanDerivation::class)($owner, $archived, $other->id, RateDerivation::Percent, '-10'))->toBeFalse()
        ->and(app(SaveRatePlanDerivation::class)($owner, $other, $parent->id, RateDerivation::Percent, '-100'))->toBeFalse();
});

test('the database refuses a plan that follows itself', function () {
    [, $property] = derivedSetup();
    $plan = RatePlan::factory()->for($property)->create(['name' => 'Loop']);

    expect(fn () => $plan->forceFill(['parent_rate_plan_id' => $plan->id, 'derivation' => 'percent', 'derivation_value' => '-5'])->save())
        ->toThrow(QueryException::class);
});

test('a parent cannot be archived while active plans follow it, but can after they are archived', function () {
    [$owner, $property, $parent, $roomType] = derivedSetup();
    $other = RatePlan::factory()->for($property)->create(['name' => 'Other']);
    $other->roomTypes()->attach($roomType);
    $child = derivedPlan($property, $parent, $roomType, RateDerivation::Percent, '-10.00');

    expect(app(ArchiveRatePlan::class)($owner, $parent))->toBeFalse()
        ->and($parent->fresh()->archived_at)->toBeNull();

    $this->actingAs($owner)
        ->from(route('settings.rate-plans.index', $property->code))
        ->post(route('settings.rate-plans.archive', [$property->code, $parent]))
        ->assertSessionHasErrors(['rate_plan' => 'Breakfast follows this plan.']);

    expect(app(ArchiveRatePlan::class)($owner, $child))->toBeTrue()
        ->and(app(ArchiveRatePlan::class)($owner, $parent))->toBeTrue();
});

test('a room type cannot be unlinked from a parent while a follower sells it', function () {
    [$owner, $property, $parent, $roomType] = derivedSetup();
    $spare = RoomType::factory()->for($property)->create(['name' => 'Spa Villa']);
    $parent->roomTypes()->attach($spare);
    $other = RatePlan::factory()->for($property)->create(['name' => 'Other']);
    $other->roomTypes()->attach([$roomType->id, $spare->id]);
    derivedPlan($property, $parent, $roomType, RateDerivation::Percent, '-10.00');

    expect(app(SyncRatePlanRoomTypes::class)($owner, $parent, [$spare->id]))->toBeFalse();

    $this->actingAs($owner)
        ->from(route('settings.rate-plans.room-types.edit', [$property->code, $parent]))
        ->put(route('settings.rate-plans.room-types.update', [$property->code, $parent]), ['room_type_ids' => [$spare->id]])
        ->assertSessionHasErrors(['room_type_ids' => 'Breakfast follows this plan for Deluxe.']);

    expect(app(SyncRatePlanRoomTypes::class)($owner, $parent, [$roomType->id]))->toBeTrue();
});

test('a derived plan can link only the room types of its parent', function () {
    [$owner, $property, $parent, $roomType] = derivedSetup();
    $child = derivedPlan($property, $parent, $roomType, RateDerivation::Percent, '-10.00');
    $stranger = RoomType::factory()->for($property)->create(['name' => 'Suite']);

    expect(app(SyncRatePlanRoomTypes::class)($owner, $child, [$roomType->id, $stranger->id]))->toBeFalse();

    $this->actingAs($owner)
        ->from(route('settings.rate-plans.room-types.edit', [$property->code, $child]))
        ->put(route('settings.rate-plans.room-types.update', [$property->code, $child]), ['room_type_ids' => [$roomType->id, $stranger->id]])
        ->assertSessionHasErrors('room_type_ids');
});

test('a follower cannot be restored while its parent is archived', function () {
    [$owner, $property, $parent, $roomType] = derivedSetup();
    $child = derivedPlan($property, $parent, $roomType, RateDerivation::Percent, '-10.00', ['archived_at' => now()]);
    $parent->forceFill(['archived_at' => now()])->save();

    expect(app(RestoreRatePlan::class)($owner, $child))->toBeFalse();

    $parent->forceFill(['archived_at' => null])->save();

    expect(app(RestoreRatePlan::class)($owner, $child))->toBeTrue();
});

test('a derived plan takes restrictions but no prices', function () {
    [$owner, $property, $parent, $roomType] = derivedSetup();
    $child = derivedPlan($property, $parent, $roomType, RateDerivation::Percent, '-10.00');
    $days = array_fill(1, 7, ['amount' => '100', 'occupancy' => []]);

    expect(app(SaveRates::class)($owner, $child, '2026-11-02', '2026-11-03', [['room_type_id' => $roomType->id, 'days' => $days]]))->toBeFalse()
        ->and(app(SaveRates::class)($owner, $child, '2026-11-02', '2026-11-03', [['room_type_id' => $roomType->id, 'days' => []]], new RateRestrictionsData(minStay: 2)))->toBe(2)
        ->and(Rate::query()->where('rate_plan_id', $child->id)->whereNotNull('amount')->count())->toBe(0)
        ->and(app(ExtendRates::class)($owner, $child, Date::parse('2026-11-01')))->toBeFalse();
});

test('the pricing tab offers eligible parents and says why following is off', function () {
    [$owner, $property, $parent, $roomType] = derivedSetup();

    $this->actingAs($owner)
        ->get(route('settings.rate-plans.pricing.edit', [$property->code, $parent]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('derivation.parents', [])
            ->where('derivation.cannotFollowReason', 'Add another plan first. This is your only rate plan.'));

    $child = derivedPlan($property, $parent, $roomType, RateDerivation::Amount, '-20.00');

    $this->actingAs($owner)
        ->get(route('settings.rate-plans.pricing.edit', [$property->code, $child]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('derivation.parentRatePlanId', $parent->id)
            ->where('derivation.direction', 'down')
            ->where('derivation.value', '20.00')
            ->where('derivation.parents.0.name', 'Basic Rate')
            ->where('derivation.cannotFollowReason', null));

    $this->actingAs($owner)
        ->get(route('settings.rate-plans.pricing.edit', [$property->code, $parent]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('derivation.cannotFollowReason', 'Breakfast follows this plan, so it needs its own prices.'));
});

test('staff save a derivation and preview it from the pricing tab', function () {
    [$owner, $property, $parent, $roomType] = derivedSetup();
    $child = RatePlan::factory()->for($property)->create(['name' => 'Member']);
    $child->roomTypes()->attach($roomType);
    derivedParentRate($parent, $roomType, now($property->settings->time_zone)->toDateString(), '800.00');
    $payload = ['parent_rate_plan_id' => $parent->id, 'derivation' => 'percent', 'direction' => 'down', 'value' => '10'];

    $this->actingAs($owner)
        ->postJson(route('settings.rate-plans.pricing.derivation.preview', [$property->code, $child]), $payload)
        ->assertOk()
        ->assertJsonPath('0.name', 'Deluxe')
        ->assertJsonPath('0.parentAmount', '800.00')
        ->assertJsonPath('0.derivedAmount', '720.00');

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.pricing.derivation.update', [$property->code, $child]), $payload)
        ->assertRedirect(route('settings.rate-plans.pricing.edit', [$property->code, $child]));

    expect($child->fresh()->derivation_value)->toBe('-10.00')
        ->and($child->fresh()->parent_rate_plan_id)->toBe($parent->id);
});

test('a derivation needs a value, a direction and a percent below 100 when going down', function (array $payload) {
    [$owner, $property, $parent, $roomType] = derivedSetup();
    $child = RatePlan::factory()->for($property)->create(['name' => 'Member']);
    $child->roomTypes()->attach($roomType);

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.pricing.derivation.update', [$property->code, $child]), ['parent_rate_plan_id' => $parent->id, ...$payload])
        ->assertSessionHasErrors();

    expect($child->fresh()->parent_rate_plan_id)->toBeNull();
})->with([
    'no value' => [['derivation' => 'percent', 'direction' => 'down']],
    'no direction' => [['derivation' => 'percent', 'value' => '10']],
    'no unit' => [['direction' => 'down', 'value' => '10']],
    'all of it off' => [['derivation' => 'percent', 'direction' => 'down', 'value' => '100']],
]);

test('another property\'s plan returns 404 on the derivation routes', function () {
    [$owner, $property] = derivedSetup();
    $foreign = RatePlan::factory()->create();

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.pricing.derivation.update', [$property->code, $foreign]), ['parent_rate_plan_id' => null])
        ->assertNotFound();
});

test('the list says which plan a derived plan follows', function () {
    [$owner, $property, $parent, $roomType] = derivedSetup();
    derivedPlan($property, $parent, $roomType, RateDerivation::Amount, '50.00');

    $this->actingAs($owner)
        ->get(route('settings.rate-plans.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('ratePlans.0.pricingLabel', 'Per room')
            ->where('ratePlans.1.pricingLabel', 'Follows Basic Rate +50'));
});

test('the discounts tab offers the parent rules only to a derived plan with none of its own', function () {
    [$owner, $property, $parent, $roomType] = derivedSetup();
    $child = derivedPlan($property, $parent, $roomType, RateDerivation::Percent, '-10.00');
    $url = route('settings.rate-plans.discounts.edit', [$property->code, $child]);

    $this->actingAs($owner)->get($url)->assertInertia(fn (Assert $page) => $page->where('parentDiscounts', null));

    RatePlanStayDiscount::factory()->create(['rate_plan_id' => $parent->id, 'min_nights' => 3, 'discount_type' => StayDiscountType::Percent, 'value' => '20.00', 'from_night' => 2]);

    $this->actingAs($owner)->get($url)->assertInertia(fn (Assert $page) => $page->where('parentDiscounts.parentName', 'Basic Rate'));

    $this->actingAs($owner)
        ->get(route('settings.rate-plans.discounts.edit', [$property->code, $parent]))
        ->assertInertia(fn (Assert $page) => $page->where('parentDiscounts', null));

    RatePlanStayDiscount::factory()->create(['rate_plan_id' => $child->id, 'min_nights' => 2]);

    $this->actingAs($owner)->get($url)->assertInertia(fn (Assert $page) => $page->where('parentDiscounts', null));
});

test('copying saves the parent discounts on the derived plan at once', function () {
    [$owner, $property, $parent, $roomType] = derivedSetup();
    $child = derivedPlan($property, $parent, $roomType, RateDerivation::Percent, '-10.00');
    RatePlanStayDiscount::factory()->create(['rate_plan_id' => $parent->id, 'min_nights' => 3, 'discount_type' => StayDiscountType::Percent, 'value' => '20.00', 'from_night' => 2]);
    RatePlanStayDiscount::factory()->create(['rate_plan_id' => $parent->id, 'min_nights' => 7, 'discount_type' => StayDiscountType::Amount, 'value' => '30.00', 'from_night' => 1]);

    $this->actingAs($owner)
        ->post(route('settings.rate-plans.discounts.copy', [$property->code, $child]))
        ->assertRedirect(route('settings.rate-plans.discounts.edit', [$property->code, $child]));

    expect($child->stayDiscounts()->orderBy('min_nights')->get()->map(fn ($d) => [$d->min_nights, $d->discount_type->value, $d->value, $d->from_night])->all())
        ->toBe([[3, 'percent', '20.00', 2], [7, 'amount', '30.00', 1]]);

    $parent->stayDiscounts()->where('min_nights', 3)->update(['value' => '50.00']);

    expect($child->stayDiscounts()->where('min_nights', 3)->value('value'))->toBe('20.00');
});

test('copying is refused for a plan that has discounts, follows nothing or has a parent without any', function () {
    [$owner, $property, $parent, $roomType] = derivedSetup();
    $child = derivedPlan($property, $parent, $roomType, RateDerivation::Percent, '-10.00');
    $action = app(CopyParentStayDiscounts::class);

    expect($action($owner, $child))->toBeFalse()
        ->and($action($owner, $parent))->toBeFalse();

    RatePlanStayDiscount::factory()->create(['rate_plan_id' => $parent->id, 'min_nights' => 3]);
    RatePlanStayDiscount::factory()->create(['rate_plan_id' => $child->id, 'min_nights' => 2]);

    expect($action($owner, $child))->toBeFalse()
        ->and($child->stayDiscounts()->count())->toBe(1);
});

test('the rate plans list marks a derived plan', function () {
    [$owner, $property, $parent, $roomType] = derivedSetup();
    derivedPlan($property, $parent, $roomType, RateDerivation::Percent, '10.00');

    $this->actingAs($owner)
        ->get(route('settings.rate-plans.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('ratePlans', fn ($plans) => collect($plans)->pluck('isDerived', 'name')->all() === ['Basic Rate' => false, 'Breakfast' => true]));
});
