<?php

use App\Actions\Property\SeedPropertyDefaults;
use App\Actions\Rates\ChangeRatePlanBasis;
use App\Actions\Rates\CreateDerivedRatePlan;
use App\Actions\Rates\ReconcileRoomTypeRatePlans;
use App\Enums\PricingBasis;
use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Enums\RateDerivation;
use App\Enums\StepState;
use App\Enums\WeekendNights;
use App\Models\Property;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Onboarding\Checks\BasicRateCheck;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/OnboardingSetup.php';
require_once __DIR__.'/../Support/BasicRateSetup.php';

beforeEach(function () {
    $this->owner = User::factory()->owner()->for(Property::factory()->onboarding())->create();
    $this->property = $this->owner->property;
    completeOnboardingBasics($this->property);
    app(SeedPropertyDefaults::class)($this->property, PropertyType::Hotel, 'MY');
    $this->property->taxes()->update(['enabled' => true]);
    $this->chalet = RoomType::factory()->for($this->property)->create(['name' => 'Garden Chalet']);
    $this->longhouse = RoomType::factory()->for($this->property)->create(['name' => 'Longhouse', 'max_adults' => 3, 'base_occupancy' => 3]);
    app(ReconcileRoomTypeRatePlans::class)($this->chalet);
    app(ReconcileRoomTypeRatePlans::class)($this->longhouse);
    $this->save = fn (array $payload) => $this->actingAs($this->owner)
        ->put(route('onboarding.basic-rate.update', $this->property->code), $payload);
    $this->plans = fn () => $this->property->ratePlans()->active()->ordered()->get();
});

test('room only rows price the seeded plan for 365 days with weekend prices', function () {
    ($this->save)(basicRatePayload([basicRateRow($this->chalet), basicRateRow($this->longhouse, 'per_room', '100.00', '130.00')]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $plans = ($this->plans)();
    $plan = $plans->sole();

    expect($plan->name)->toBe('Basic Rate')
        ->and($plan->pricing_basis)->toBe(PricingBasis::PerRoom)
        ->and($plan->roomTypes()->count())->toBe(2)
        ->and(Rate::query()->where('rate_plan_id', $plan->id)->where('room_type_id', $this->chalet->id)->count())->toBe(365)
        ->and(rateOn($plan, $this->chalet, '2026-10-14'))->toBe('180.00')
        ->and(rateOn($plan, $this->chalet, '2026-10-16'))->toBe('220.00')
        ->and(rateOn($plan, $this->chalet, '2026-10-17'))->toBe('220.00')
        ->and(rateOn($plan, $this->chalet, '2026-10-18'))->toBe('180.00')
        ->and(rateOn($plan, $this->longhouse, '2026-10-16'))->toBe('130.00')
        ->and($this->property->settings->refresh()->weekend_nights)->toBe(WeekendNights::FriSat);
});

test('same every night sends the weekday price to all 7 nights and saves the weekend nights', function () {
    ($this->save)(basicRatePayload([basicRateRow($this->chalet, 'per_room', '150.00', null), basicRateRow($this->longhouse, 'per_room', '90', null)], ['same_every_night' => true, 'weekend_nights' => 'sat_sun']))
        ->assertSessionHasNoErrors();

    $plan = ($this->plans)()->sole();

    expect(rateOn($plan, $this->chalet, '2026-10-16'))->toBe('150.00')
        ->and(rateOn($plan, $this->chalet, '2026-10-18'))->toBe('150.00')
        ->and(rateOn($plan, $this->longhouse, '2026-10-17'))->toBe('90.00')
        ->and($this->property->settings->refresh()->weekend_nights)->toBe(WeekendNights::SatSun);
});

test('guest only rows switch the seeded plan to per guest with starter child prices', function () {
    ($this->save)(basicRatePayload([basicRateRow($this->chalet, 'per_guest', '85.00', '95.00'), basicRateRow($this->longhouse, 'per_guest', '60.00', '70.00')]))
        ->assertSessionHasNoErrors();

    $plan = ($this->plans)()->sole();

    expect($plan->name)->toBe('Basic Rate')
        ->and($plan->pricing_basis)->toBe(PricingBasis::PerGuest)
        ->and($plan->childPrices()->count())->toBe($this->property->ageGroups()->count())
        ->and(rateOn($plan, $this->chalet, '2026-10-14'))->toBe('85.00');
});

test('mixed rows split into a Room plan and a Guest plan', function () {
    ($this->save)(basicRatePayload([basicRateRow($this->chalet), basicRateRow($this->longhouse, 'per_guest', '85.00', '95.00')]))
        ->assertSessionHasNoErrors();

    $plans = ($this->plans)()->keyBy('name');
    $room = $plans->get('Basic Rate / Room');
    $guest = $plans->get('Basic Rate / Guest');

    expect($plans)->toHaveCount(2)
        ->and($room->pricing_basis)->toBe(PricingBasis::PerRoom)
        ->and($room->roomTypes()->pluck('room_types.id')->all())->toBe([$this->chalet->id])
        ->and($guest->pricing_basis)->toBe(PricingBasis::PerGuest)
        ->and($guest->roomTypes()->pluck('room_types.id')->all())->toBe([$this->longhouse->id])
        ->and(rateOn($guest, $this->longhouse, '2026-10-16'))->toBe('95.00');
});

test('going back to one basis keeps one plan and archives the other', function () {
    ($this->save)(basicRatePayload([basicRateRow($this->chalet), basicRateRow($this->longhouse, 'per_guest', '85.00', '95.00')]));
    ($this->save)(basicRatePayload([basicRateRow($this->chalet), basicRateRow($this->longhouse, 'per_room', '100.00', '120.00')]))
        ->assertSessionHasNoErrors();

    $plan = ($this->plans)()->sole();

    expect($plan->name)->toBe('Basic Rate')
        ->and($plan->pricing_basis)->toBe(PricingBasis::PerRoom)
        ->and($plan->roomTypes()->count())->toBe(2)
        ->and(rateOn($plan, $this->longhouse, '2026-10-14'))->toBe('100.00')
        ->and(RatePlan::query()->where('property_id', $this->property->id)->whereNotNull('archived_at')->count())->toBe(1);
});

test('a second save replaces the prices', function () {
    ($this->save)(basicRatePayload([basicRateRow($this->chalet), basicRateRow($this->longhouse)]));
    ($this->save)(basicRatePayload([basicRateRow($this->chalet, 'per_room', '200.00', '250.00'), basicRateRow($this->longhouse)]));

    $plan = ($this->plans)()->sole();

    expect(rateOn($plan, $this->chalet, '2026-10-14'))->toBe('200.00')
        ->and(Rate::query()->where('rate_plan_id', $plan->id)->where('room_type_id', $this->chalet->id)->count())->toBe(365);
});

test('a save redirects to the next listed step or back', function () {
    ($this->save)(basicRatePayload([basicRateRow($this->chalet), basicRateRow($this->longhouse)]))
        ->assertRedirect();
});

test('every room type sold by room needs a row', function () {
    ($this->save)(basicRatePayload([basicRateRow($this->chalet)]))
        ->assertSessionHasErrors('rows');

    expect(Rate::query()->count())->toBe(0);
});

test('prices must be more than 0 with at most 2 decimals and the weekend price is needed unless same every night', function () {
    ($this->save)(basicRatePayload([basicRateRow($this->chalet, 'per_room', '0', '10.123'), basicRateRow($this->longhouse, 'per_room', '50.00', null)]))
        ->assertSessionHasErrors(['rows.0.weekday', 'rows.0.weekend', 'rows.1.weekend']);

    expect(Rate::query()->count())->toBe(0);
});

test('a room type of another property or a bed room type is refused', function () {
    $other = RoomType::factory()->for(Property::factory())->create();
    $bed = RoomType::factory()->bed()->for($this->property)->create();

    ($this->save)(basicRatePayload([basicRateRow($this->chalet), basicRateRow($this->longhouse), basicRateRow($other), basicRateRow($bed)]))
        ->assertSessionHasErrors(['rows.2.room_type_id', 'rows.3.room_type_id']);
});

test('the save is refused until room types are done', function () {
    RoomType::query()->where('property_id', $this->property->id)->update(['archived_at' => now()]);

    ($this->save)(basicRatePayload([basicRateRow($this->chalet)]))
        ->assertSessionHasErrors('onboarding');
});

test('changing the basis is refused after go-live, with followers, and for the same basis does nothing', function () {
    $plan = ($this->plans)()->sole();
    $change = app(ChangeRatePlanBasis::class);

    expect($change($this->owner, $plan, PricingBasis::PerRoom))->toBeTrue();

    app(CreateDerivedRatePlan::class)($this->owner, $plan, 'Breakfast', RateDerivation::Percent, '10.00', [$this->chalet->id]);

    expect($change($this->owner, $plan, PricingBasis::PerGuest))->toBeFalse()
        ->and($plan->refresh()->pricing_basis)->toBe(PricingBasis::PerRoom);

    $this->property->update(['status' => PropertyStatus::Live]);
    $loneParent = RatePlan::factory()->for($this->property)->create(['name' => 'Other']);

    expect($change($this->owner, $loneParent, PricingBasis::PerGuest))->toBeFalse();
});

test('the check is not started, in progress, then done once every room type has 30 days', function () {
    $check = new BasicRateCheck;

    expect($check->state($this->property))->toBe(StepState::NotStarted);

    ($this->save)(basicRatePayload([basicRateRow($this->chalet), basicRateRow($this->longhouse)]));

    expect($check->state($this->property))->toBe(StepState::Done);

    Rate::query()->where('room_type_id', $this->longhouse->id)->whereDate('date', '2026-10-20')->delete();

    expect($check->state($this->property))->toBe(StepState::InProgress);

    Rate::query()->where('room_type_id', $this->longhouse->id)->delete();

    expect($check->state($this->property))->toBe(StepState::InProgress);

    Rate::query()->delete();

    expect($check->state($this->property))->toBe(StepState::NotStarted);
});

test('with no room type sold by room the check is done only when a bed room type is active', function () {
    $check = new BasicRateCheck;
    $this->property->roomTypes()->update(['archived_at' => now()]);

    expect($check->state($this->property))->toBe(StepState::NotStarted);

    $dorm = RoomType::factory()->bed()->for($this->property)->create();

    expect($check->state($this->property))->toBe(StepState::Done);

    $dorm->update(['archived_at' => now()]);

    expect($check->state($this->property))->toBe(StepState::NotStarted);
});

test('a bed room type does not count toward the room types to price', function () {
    RoomType::factory()->bed()->for($this->property)->create();

    expect((new BasicRateCheck)->state($this->property))->toBe(StepState::NotStarted);
});

test('the page says whether any active room type is sold by bed', function () {
    $page = fn () => $this->actingAs($this->owner)->get(route('onboarding.basic-rate.edit', $this->property->code));

    $page()->assertInertia(fn (Assert $page) => $page->where('hasBedTypes', false));

    $dorm = RoomType::factory()->bed()->for($this->property)->create();

    $page()->assertInertia(fn (Assert $page) => $page->where('hasBedTypes', true)->has('rows', 2));

    $dorm->update(['archived_at' => now()]);

    $page()->assertInertia(fn (Assert $page) => $page->where('hasBedTypes', false));
});

test('the page returns the saved rows, the weekend nights and the guest pays preview', function () {
    ($this->save)(basicRatePayload([basicRateRow($this->chalet), basicRateRow($this->longhouse, 'per_guest', '85.00', '95.00')]));

    $this->actingAs($this->owner)
        ->get(route('onboarding.basic-rate.edit', $this->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/basic-rate')
            ->where('weekendNights', 'fri_sat')
            ->where('sameEveryNight', false)
            ->where('locked', false)
            ->has('rows', 2)
            ->where('rows.0.name', 'Garden Chalet')
            ->where('rows.0.priceBy', 'per_room')
            ->where('rows.0.weekday', '180.00')
            ->where('rows.0.weekend', '220.00')
            ->where('rows.1.priceBy', 'per_guest')
            ->where('rows.1.baseOccupancy', 3)
            ->has('preview.rows', 2)
            ->where('preview.rows.1.adults', 3)
            ->has('moreRates', 0));
});

test('same every night is preselected when all 7 nights match', function () {
    ($this->save)(basicRatePayload([basicRateRow($this->chalet, 'per_room', '150.00', null), basicRateRow($this->longhouse, 'per_room', '90.00', null)], ['same_every_night' => true]));

    $this->actingAs($this->owner)
        ->get(route('onboarding.basic-rate.edit', $this->property->code))
        ->assertInertia(fn (Assert $page) => $page->where('sameEveryNight', true));
});

test('the preview adds the room taxes and multiplies a guest price by the base occupancy', function () {
    $response = $this->actingAs($this->owner)
        ->postJson(route('onboarding.basic-rate.preview', $this->property->code), ['rows' => [
            ['room_type_id' => $this->chalet->id, 'price_by' => 'per_room', 'weekday' => '100.00', 'weekend' => ''],
            ['room_type_id' => $this->longhouse->id, 'price_by' => 'per_guest', 'weekday' => '50', 'weekend' => '1.'],
        ]])
        ->assertOk();

    $chalet = $response->json('rows.0');
    $longhouse = $response->json('rows.1');

    expect($chalet['adults'])->toBe(1)
        ->and((float) $chalet['weekdayTotal'])->toBeGreaterThanOrEqual(100.0)
        ->and($chalet['weekendTotal'])->toBeNull()
        ->and($longhouse['adults'])->toBe(3)
        ->and((float) $longhouse['weekdayTotal'])->toBeGreaterThanOrEqual(150.0)
        ->and($longhouse['weekendTotal'])->toBeNull();
});

test('the preview refuses a room type of another property', function () {
    $other = RoomType::factory()->for(Property::factory())->create();

    $this->actingAs($this->owner)
        ->postJson(route('onboarding.basic-rate.preview', $this->property->code), ['rows' => [
            ['room_type_id' => $other->id, 'price_by' => 'per_room', 'weekday' => '100.00', 'weekend' => ''],
        ]])
        ->assertUnprocessable();
});
