<?php

use App\Actions\Rates\ExtendRates;
use App\Actions\Rates\SaveRates;
use App\Enums\DefaultRoleEnum;
use App\Enums\PermissionEnum;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Rate;
use App\Models\RateOccupancyPrice;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Rates\RatesEndingCheck;
use App\Services\Rates\RestrictionCheck;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/** @return array{0: User, 1: Property, 2: RatePlan, 3: RoomType} */
function ratesSetup(): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create();
    $plan = RatePlan::factory()->for($property)->create(['name' => 'Basic']);
    $roomType = RoomType::factory()->for($property)->create();
    $plan->roomTypes()->attach($roomType);

    return [$owner, $property, $plan, $roomType];
}

/** @return array<string, mixed> */
function pricingInput(RoomType $roomType, array $overrides = []): array
{
    $days = collect(range(1, 7))->map(fn (int $weekday): array => [
        'weekday' => $weekday,
        'amount' => in_array($weekday, [5, 6], true) ? '250.00' : '180',
        'occupancy' => [],
    ])->all();

    return array_merge([
        'from' => '2026-10-05',
        'to' => '2026-10-11',
        'room_types' => [['room_type_id' => $roomType->id, 'days' => $days]],
    ], $overrides);
}

test('the pricing form saves weekday and weekend prices for every date of the range', function () {
    [$owner, $property, $plan, $roomType] = ratesSetup();

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.pricing.update', [$property->code, $plan]), pricingInput($roomType))
        ->assertRedirect(route('settings.rate-plans.pricing.edit', [$property->code, $plan]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Prices saved for 7 dates.']);

    $rates = Rate::query()->where('rate_plan_id', $plan->id)->orderBy('date')->get()->keyBy(fn (Rate $rate) => $rate->date->toDateString());

    expect($rates)->toHaveCount(7)
        ->and($rates['2026-10-05']->amount)->toBe('180.00')
        ->and($rates['2026-10-09']->amount)->toBe('250.00')
        ->and($rates['2026-10-10']->amount)->toBe('250.00')
        ->and($rates['2026-10-11']->amount)->toBe('180.00')
        ->and(Activity::query()->where('event', 'rates.updated')->count())->toBe(1);
});

test('several room types save in one request, each with the same weekdays and adult counts', function () {
    [$owner, $property, $plan, $roomType] = ratesSetup();
    $second = RoomType::factory()->for($property)->create();
    $plan->roomTypes()->attach($second);
    $input = pricingInput($roomType, ['to' => '2026-10-05']);
    $input['room_types'][0]['days'][0]['occupancy'] = [['adults' => 1, 'amount' => '120'], ['adults' => 2, 'amount' => '150']];
    $input['room_types'][] = ['room_type_id' => $second->id, 'days' => $input['room_types'][0]['days']];

    $this->actingAs($owner)->put(route('settings.rate-plans.pricing.update', [$property->code, $plan]), $input)->assertSessionHasNoErrors();

    expect(Rate::query()->count())->toBe(2)
        ->and(RateOccupancyPrice::query()->count())->toBe(4);
});

test('a weekday or adult count repeated within one room type is rejected', function () {
    [$owner, $property, $plan, $roomType] = ratesSetup();
    $input = pricingInput($roomType);
    $input['room_types'][0]['days'][1]['weekday'] = 1;

    $this->actingAs($owner)->put(route('settings.rate-plans.pricing.update', [$property->code, $plan]), $input)->assertSessionHasErrors('room_types.0.days.1.weekday');

    $input = pricingInput($roomType);
    $input['room_types'][0]['days'][0]['occupancy'] = [['adults' => 2, 'amount' => '100'], ['adults' => 2, 'amount' => '110']];

    $this->put(route('settings.rate-plans.pricing.update', [$property->code, $plan]), $input)->assertSessionHasErrors('room_types.0.days.0.occupancy.1.adults');
    expect(Rate::query()->count())->toBe(0);
});

test('saving again changes prices without creating duplicate rows, and keeps restrictions', function () {
    [$owner, $property, $plan, $roomType] = ratesSetup();
    Rate::factory()->stopSell()->create([
        'room_type_id' => $roomType->id, 'rate_plan_id' => $plan->id, 'date' => '2026-10-05', 'amount' => '99.00', 'min_stay' => 2,
    ]);

    $this->actingAs($owner)->put(route('settings.rate-plans.pricing.update', [$property->code, $plan]), pricingInput($roomType))
        ->assertSessionHasNoErrors();

    $rate = Rate::query()->where('date', '2026-10-05')->sole();

    expect(Rate::query()->count())->toBe(7)
        ->and($rate->amount)->toBe('180.00')
        ->and($rate->stop_sell)->toBeTrue()
        ->and($rate->min_stay)->toBe(2);
});

test('a weekday with no amount leaves that price alone', function () {
    [$owner, $property, $plan, $roomType] = ratesSetup();
    Rate::factory()->create(['room_type_id' => $roomType->id, 'rate_plan_id' => $plan->id, 'date' => '2026-10-05', 'amount' => '77.00']);
    $input = pricingInput($roomType);
    $input['room_types'][0]['days'][0]['amount'] = '';

    $this->actingAs($owner)->put(route('settings.rate-plans.pricing.update', [$property->code, $plan]), $input)->assertSessionHasNoErrors();

    expect(Rate::query()->where('date', '2026-10-05')->sole()->amount)->toBe('77.00')
        ->and(Rate::query()->where('date', '2026-10-06')->sole()->amount)->toBe('180.00')
        ->and(Rate::query()->count())->toBe(7);
});

test('occupancy prices are replaced with the ones sent', function () {
    [$owner, $property, $plan, $roomType] = ratesSetup();
    $rate = Rate::factory()->create(['room_type_id' => $roomType->id, 'rate_plan_id' => $plan->id, 'date' => '2026-10-05']);
    RateOccupancyPrice::factory()->create(['rate_id' => $rate->id, 'adults' => 1, 'amount' => '120.00']);
    $input = pricingInput($roomType, ['to' => '2026-10-05']);
    $input['room_types'][0]['days'][0]['occupancy'] = [['adults' => 2, 'amount' => '200.5']];

    $this->actingAs($owner)->put(route('settings.rate-plans.pricing.update', [$property->code, $plan]), $input)->assertSessionHasNoErrors();

    expect($rate->occupancyPrices()->get()->map(fn ($price) => [$price->adults, $price->amount])->all())->toBe([[2, '200.50']]);
});

test('restrictions apply to the whole range and create rows without a price', function () {
    [$owner, $property, $plan, $roomType] = ratesSetup();
    $input = pricingInput($roomType, ['restrictions' => ['min_stay' => 2, 'stop_sell' => true]]);
    $input['room_types'][0]['days'] = [];

    $this->actingAs($owner)->put(route('settings.rate-plans.pricing.update', [$property->code, $plan]), $input)->assertSessionHasNoErrors();

    expect(Rate::query()->count())->toBe(7)
        ->and(Rate::query()->whereNotNull('amount')->count())->toBe(0)
        ->and(Rate::query()->where('min_stay', 2)->where('stop_sell', true)->count())->toBe(7);
});

test('invalid pricing input is rejected', function (string $case) {
    [$owner, $property, $plan, $roomType] = ratesSetup();
    $other = RoomType::factory()->for($property)->create();
    $input = match ($case) {
        'end before start' => pricingInput($roomType, ['from' => '2026-10-10', 'to' => '2026-10-01']),
        'range too long' => pricingInput($roomType, ['to' => '2029-01-01']),
        'room type the plan does not sell' => pricingInput($other),
        'negative amount' => tap(pricingInput($roomType), function (array &$input): void {
            $input['room_types'][0]['days'][0]['amount'] = '-1';
        }),
        'more adults than the room sleeps' => tap(pricingInput($roomType), function (array &$input): void {
            $input['room_types'][0]['days'][0]['occupancy'] = [['adults' => 9, 'amount' => '100']];
        }),
        'max stay below min stay' => pricingInput($roomType, ['restrictions' => ['min_stay' => 3, 'max_stay' => 2]]),
    };

    $this->actingAs($owner)->put(route('settings.rate-plans.pricing.update', [$property->code, $plan]), $input)->assertSessionHasErrors();

    expect(Rate::query()->count())->toBe(0);
})->with(['end before start', 'range too long', 'room type the plan does not sell', 'negative amount', 'more adults than the room sleeps', 'max stay below min stay']);

test('the action refuses an archived plan and a merged min stay above the max stay', function () {
    [$owner, $property, $plan, $roomType] = ratesSetup();
    $days = [1 => ['amount' => '100', 'occupancy' => []]];
    $roomTypes = [['room_type_id' => $roomType->id, 'days' => $days]];
    Rate::factory()->create(['room_type_id' => $roomType->id, 'rate_plan_id' => $plan->id, 'date' => '2026-10-05', 'max_stay' => 2]);

    expect(app(SaveRates::class)($owner, $plan, '2026-10-05', '2026-10-05', $roomTypes, ['min_stay' => 4]))->toBeFalse()
        ->and(Rate::query()->sole()->min_stay)->toBeNull();

    $plan->update(['archived_at' => now()]);

    expect(app(SaveRates::class)($owner, $plan, '2026-10-05', '2026-10-05', $roomTypes))->toBeFalse()
        ->and(Activity::query()->where('event', 'rates.updated')->count())->toBe(0);
});

test('the pricing page lists the plan room types with how far their prices run', function () {
    [$owner, $property, $plan, $roomType] = ratesSetup();
    $property->settings->update(['weekend_nights' => 'sat_sun']);
    $today = now($property->settings->time_zone)->toDateString();
    Rate::factory()->create(['room_type_id' => $roomType->id, 'rate_plan_id' => $plan->id, 'date' => Date::parse($today)->addDays(10)->toDateString()]);
    RoomType::factory()->for($property)->create();

    $this->actingAs($owner)->get(route('settings.rate-plans.pricing.edit', [$property->code, $plan]))
        ->assertInertia(fn (Assert $page) => $page->component('settings/rate-plans/pricing')
            ->has('roomTypes', 1)
            ->where('roomTypes.0.id', $roomType->id)
            ->where('roomTypes.0.pricedUntil', Date::parse($today)->addDays(10)->toDateString())
            ->where('roomTypes.0.ending', true)
            ->where('weekendNights', 'sat_sun')
            ->where('weekendNightsOptions.1.isoWeekdays', [6, 7]));
});

test('pricing is limited to settings managers of the property', function () {
    [$owner, $property, $plan, $roomType] = ratesSetup();
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create(['property_id' => $property->id]);
    [, $otherProperty, $otherPlan] = ratesSetup();

    $this->actingAs($frontDesk)->get(route('settings.rate-plans.pricing.edit', [$property->code, $plan]))->assertForbidden();
    $this->actingAs($owner)->get(route('settings.rate-plans.pricing.edit', [$property->code, $otherPlan]))->assertNotFound();
    $this->put(route('settings.rate-plans.pricing.update', [$property->code, $otherPlan]), pricingInput($roomType))->assertNotFound();
    $this->get(route('settings.rate-plans.pricing.edit', [$otherProperty->code, $otherPlan]))->assertNotFound();
});

test('extending copies the last seven days forward for twelve months, without date-specific restrictions', function () {
    [$owner, $property, $plan, $roomType] = ratesSetup();
    $monday = Date::parse('2026-10-05');

    for ($i = 0; $i < 7; $i++) {
        $rate = Rate::factory()->stopSell()->create([
            'room_type_id' => $roomType->id, 'rate_plan_id' => $plan->id, 'date' => $monday->addDays($i)->toDateString(),
            'amount' => (100 + $i).'.00', 'min_stay' => 2,
        ]);
        RateOccupancyPrice::factory()->create(['rate_id' => $rate->id, 'adults' => 1, 'amount' => (90 + $i).'.00']);
    }
    // An older week must not be the source.
    Rate::factory()->create(['room_type_id' => $roomType->id, 'rate_plan_id' => $plan->id, 'date' => '2026-09-28', 'amount' => '1.00']);

    $written = app(ExtendRates::class)($owner, $plan, Date::parse('2026-10-01'));

    $first = Rate::query()->where('date', '2026-10-12')->sole();
    $last = Rate::query()->max('date');

    expect($written)->toBe(365)
        ->and($first->amount)->toBe('100.00')
        ->and($first->min_stay)->toBe(2)
        ->and($first->stop_sell)->toBeFalse()
        ->and($first->occupancyPrices()->sole()->amount)->toBe('90.00')
        ->and(Rate::query()->where('date', '2026-10-17')->sole()->amount)->toBe('105.00')
        ->and($last)->toStartWith('2027-10-11')
        ->and(Activity::query()->where('event', 'rates.extended')->count())->toBe(1);
});

test('extending skips room types with no price, and refuses an archived plan', function () {
    [$owner, $property, $plan, $roomType] = ratesSetup();

    expect(app(ExtendRates::class)($owner, $plan, now()))->toBe(0)
        ->and(Rate::query()->count())->toBe(0);

    $this->actingAs($owner)->post(route('settings.rate-plans.pricing.extend', [$property->code, $plan]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'There are no prices to copy yet. Set prices first.']);

    $plan->update(['archived_at' => now()]);

    expect(app(ExtendRates::class)($owner, $plan, now()))->toBeFalse();
});

test('extending through the page adds dates and reports the count', function () {
    [$owner, $property, $plan, $roomType] = ratesSetup();
    $today = now($property->settings->time_zone)->startOfDay();
    Rate::factory()->create(['room_type_id' => $roomType->id, 'rate_plan_id' => $plan->id, 'date' => $today->toDateString()]);

    $this->actingAs($owner)->post(route('settings.rate-plans.pricing.extend', [$property->code, $plan]))
        ->assertSessionHasNoErrors();

    expect(Rate::query()->count())->toBeGreaterThan(1)
        ->and(app(RatesEndingCheck::class)->isEnding(Rate::query()->max('date'), $today))->toBeFalse();
});

test('rates are ending when a room type has no price or its prices end within 60 days', function () {
    [$owner, $property, $plan, $roomType] = ratesSetup();
    $check = app(RatesEndingCheck::class);
    $today = Date::parse('2026-10-01');

    expect($check->pricedUntil($plan))->toBe([$roomType->id => null])
        ->and($check->isEnding(null, $today))->toBeTrue()
        ->and($check->isEnding('2026-11-29', $today))->toBeTrue()
        ->and($check->isEnding('2026-11-30', $today))->toBeFalse();

    Rate::factory()->create(['room_type_id' => $roomType->id, 'rate_plan_id' => $plan->id, 'date' => '2026-12-01']);
    Rate::factory()->create(['room_type_id' => $roomType->id, 'rate_plan_id' => $plan->id, 'date' => '2027-01-01', 'amount' => null]);

    expect($check->pricedUntil($plan))->toBe([$roomType->id => '2026-12-01']);
});

test('the restriction check reports every rule a stay breaks', function () {
    [$owner, $property, $plan, $roomType] = ratesSetup();
    $rate = fn (string $date, array $attributes = []) => Rate::factory()->create([
        'room_type_id' => $roomType->id, 'rate_plan_id' => $plan->id, 'date' => $date,
    ] + $attributes);
    $rate('2026-10-05', ['min_stay' => 3, 'max_stay' => 5, 'closed_to_arrival' => true]);
    $rate('2026-10-06', ['stop_sell' => true]);
    $rate('2026-10-07', ['closed_to_departure' => true]);
    $check = fn (string $arrival, string $departure) => app(RestrictionCheck::class)->violations($plan->id, $roomType->id, Date::parse($arrival), Date::parse($departure));

    expect($check('2026-10-05', '2026-10-07'))->toBe([
        'Not for sale on 06 Oct 2026.',
        'Closed to arrival on 05 Oct 2026.',
        'Min stay 3 nights.',
        'Closed to departure on 07 Oct 2026.',
    ])
        ->and($check('2026-10-05', '2026-10-12'))->toContain('Max stay 5 nights.')
        ->and($check('2026-10-20', '2026-10-22'))->toBe([])
        ->and($check('2026-10-22', '2026-10-22'))->toBe([]);
});

test('owners and managers hold the override permission, front desk does not', function () {
    expect(DefaultRoleEnum::Owner->permissions())->toContain(PermissionEnum::RatesOverrideRestrictions)
        ->and(DefaultRoleEnum::Manager->permissions())->toContain(PermissionEnum::RatesOverrideRestrictions)
        ->and(DefaultRoleEnum::FrontDesk->permissions())->not->toContain(PermissionEnum::RatesOverrideRestrictions);
});
