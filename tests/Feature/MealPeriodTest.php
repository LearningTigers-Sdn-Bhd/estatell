<?php

use App\Actions\Meals\SeedMealPeriods;
use App\Enums\DefaultMealPeriod;
use App\Enums\DefaultRoleEnum;
use App\Models\MealPeriod;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/** @param array<string, mixed> $overrides */
function mealPeriodPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Hi-tea',
        'starts_at' => '15:30',
        'ends_at' => '17:00',
        'active' => true,
    ], $overrides);
}

function seededMealPeriodProperty(): array
{
    $owner = User::factory()->owner()->create();
    app(SeedMealPeriods::class)($owner->property, 'MY');

    return [$owner, $owner->property];
}

test('default meal periods expose their names and times', function () {
    expect(DefaultMealPeriod::Breakfast->label())->toBe('Breakfast')
        ->and(DefaultMealPeriod::Breakfast->startsAt())->toBe('07:00')
        ->and(DefaultMealPeriod::Breakfast->endsAt())->toBe('10:00')
        ->and(DefaultMealPeriod::Lunch->startsAt())->toBe('12:00')
        ->and(DefaultMealPeriod::Lunch->endsAt())->toBe('14:30')
        ->and(DefaultMealPeriod::Dinner->startsAt())->toBe('19:00')
        ->and(DefaultMealPeriod::Dinner->endsAt())->toBe('22:00');
});

test('Malaysia properties receive three meal periods idempotently', function () {
    $property = Property::factory()->create(['country_code' => 'MY']);

    app(SeedMealPeriods::class)($property, 'MY');
    $property->mealPeriods()->where('name', 'Lunch')->update(['name' => 'Midday meal', 'active' => false]);
    app(SeedMealPeriods::class)($property, 'MY');

    $periods = $property->mealPeriods()->ordered()->get();
    expect($periods->pluck('name')->all())->toBe(['Breakfast', 'Midday meal', 'Dinner'])
        ->and($periods->pluck('starts_at')->all())->toBe(['07:00:00', '12:00:00', '19:00:00'])
        ->and($periods->pluck('ends_at')->all())->toBe(['10:00:00', '14:30:00', '22:00:00'])
        ->and($periods->firstWhere('name', 'Midday meal')->active)->toBeFalse();
});

test('unsupported countries receive no meal period presets', function () {
    $property = Property::factory()->create();

    expect(fn () => app(SeedMealPeriods::class)($property, 'SG'))
        ->toThrow(InvalidArgumentException::class);

    expect($property->mealPeriods()->count())->toBe(0);
});

test('the page lists this property\'s periods by start time', function () {
    [$owner, $property] = seededMealPeriodProperty();
    MealPeriod::factory()->for($property)->inactive()->create(['name' => 'Supper', 'starts_at' => '22:30', 'ends_at' => '23:30']);
    MealPeriod::factory()->create(['name' => 'Other property']);

    $this->actingAs($owner)
        ->get(route('settings.meal-periods.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/meal-periods/index')
            ->has('mealPeriods', 4)
            ->where('mealPeriods.0.name', 'Breakfast')
            ->where('mealPeriods.0.startsAt', '07:00')
            ->where('mealPeriods.0.endsAt', '10:00')
            ->where('mealPeriods.3.name', 'Supper')
            ->where('navigation.contexts.1.groups.2.label', 'Rates')
            ->where('navigation.contexts.1.groups.2.items.0.title', 'Meal periods')
            ->where('navigation.contexts.1.groups.2.items.0.active', true));
});

test('a new period lands between lunch and dinner by its start time', function () {
    [$owner, $property] = seededMealPeriodProperty();

    $this->actingAs($owner)
        ->post(route('settings.meal-periods.store', $property->code), mealPeriodPayload(['name' => ' Hi-tea ']))
        ->assertRedirect(route('settings.meal-periods.index', $property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Hi-tea created.']);

    expect($property->mealPeriods()->ordered()->pluck('name')->all())
        ->toBe(['Breakfast', 'Lunch', 'Hi-tea', 'Dinner'])
        ->and(Activity::query()->where('event', 'meal_period.created')->count())->toBe(1);
});

test('periods that only touch another period are accepted', function () {
    [$owner, $property] = seededMealPeriodProperty();

    $this->actingAs($owner)
        ->post(route('settings.meal-periods.store', $property->code), mealPeriodPayload(['starts_at' => '14:30', 'ends_at' => '19:00']))
        ->assertSessionHasNoErrors();

    expect($property->mealPeriods()->count())->toBe(4);
});

test('new periods are validated', function (array $overrides, string $field) {
    [$owner, $property] = seededMealPeriodProperty();

    $this->actingAs($owner)
        ->post(route('settings.meal-periods.store', $property->code), mealPeriodPayload($overrides))
        ->assertSessionHasErrors($field);

    expect($property->mealPeriods()->count())->toBe(3);
})->with([
    'name is required' => [['name' => ''], 'name'],
    'name is unique ignoring case' => [['name' => 'lUnCh'], 'name'],
    'start is required' => [['starts_at' => ''], 'starts_at'],
    'start is a time' => [['starts_at' => '25:99'], 'starts_at'],
    'end is a time' => [['ends_at' => 'late'], 'ends_at'],
    'end is after start' => [['starts_at' => '17:00', 'ends_at' => '15:30'], 'ends_at'],
    'end differs from start' => [['starts_at' => '17:00', 'ends_at' => '17:00'], 'ends_at'],
    'overlaps the start of lunch' => [['starts_at' => '11:00', 'ends_at' => '13:00'], 'ends_at'],
    'sits inside dinner' => [['starts_at' => '20:00', 'ends_at' => '21:00'], 'ends_at'],
    'contains breakfast' => [['starts_at' => '06:00', 'ends_at' => '11:00'], 'ends_at'],
]);

test('an inactive period does not block an overlapping one', function () {
    [$owner, $property] = seededMealPeriodProperty();
    $property->mealPeriods()->where('name', 'Lunch')->update(['active' => false]);

    $this->actingAs($owner)
        ->post(route('settings.meal-periods.store', $property->code), mealPeriodPayload(['starts_at' => '12:30', 'ends_at' => '13:30']))
        ->assertSessionHasNoErrors();

    expect($property->mealPeriods()->count())->toBe(4);
});

test('an inactive period may overlap when it is saved inactive', function () {
    [$owner, $property] = seededMealPeriodProperty();

    $this->actingAs($owner)
        ->post(route('settings.meal-periods.store', $property->code), mealPeriodPayload(['starts_at' => '12:30', 'ends_at' => '13:30', 'active' => false]))
        ->assertSessionHasNoErrors();

    expect($property->mealPeriods()->where('active', false)->count())->toBe(1);
});

test('owners edit a period and are not blocked by its own old times', function () {
    [$owner, $property] = seededMealPeriodProperty();
    $lunch = $property->mealPeriods()->where('name', 'Lunch')->sole();

    $this->actingAs($owner)
        ->put(route('settings.meal-periods.update', [$property->code, $lunch]), mealPeriodPayload([
            'name' => ' Midday meal ', 'starts_at' => '12:30', 'ends_at' => '15:00',
        ]))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Midday meal saved.']);

    expect($lunch->refresh()->name)->toBe('Midday meal')
        ->and($lunch->starts_at)->toBe('12:30:00')
        ->and($lunch->ends_at)->toBe('15:00:00')
        ->and(Activity::query()->where('event', 'meal_period.updated')->count())->toBe(1);
});

test('editing times into another active period is refused', function () {
    [$owner, $property] = seededMealPeriodProperty();
    $lunch = $property->mealPeriods()->where('name', 'Lunch')->sole();

    $this->actingAs($owner)
        ->put(route('settings.meal-periods.update', [$property->code, $lunch]), mealPeriodPayload([
            'name' => 'Lunch', 'starts_at' => '12:00', 'ends_at' => '19:30',
        ]))
        ->assertSessionHasErrors('ends_at');

    expect($lunch->refresh()->ends_at)->toBe('14:30:00');
});

test('reactivating a period into an overlap is refused', function () {
    [$owner, $property] = seededMealPeriodProperty();
    $property->mealPeriods()->where('name', 'Lunch')->update(['active' => false]);
    MealPeriod::factory()->for($property)->create(['name' => 'Brunch', 'starts_at' => '13:00', 'ends_at' => '14:00']);
    $lunch = $property->mealPeriods()->where('name', 'Lunch')->sole();

    $this->actingAs($owner)
        ->put(route('settings.meal-periods.update', [$property->code, $lunch]), mealPeriodPayload([
            'name' => 'Lunch', 'starts_at' => '12:00', 'ends_at' => '14:30', 'active' => true,
        ]))
        ->assertSessionHasErrors('ends_at');

    expect($lunch->refresh()->active)->toBeFalse();
});

test('owners deactivate a period and no-op saves are not logged', function () {
    [$owner, $property] = seededMealPeriodProperty();
    $lunch = $property->mealPeriods()->where('name', 'Lunch')->sole();
    $url = route('settings.meal-periods.update', [$property->code, $lunch]);
    $payload = mealPeriodPayload(['name' => 'Lunch', 'starts_at' => '12:00', 'ends_at' => '14:30']);

    $this->actingAs($owner)->put($url, $payload);
    expect(Activity::query()->where('event', 'meal_period.updated')->count())->toBe(0);

    $this->put($url, [...$payload, 'active' => false]);
    $this->put($url, [...$payload, 'active' => false]);

    expect($lunch->refresh()->active)->toBeFalse()
        ->and(Activity::query()->where('event', 'meal_period.updated')->count())->toBe(1);
});

test('routes enforce settings permission and property scoping', function () {
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    $owner = User::factory()->owner()->create();
    $otherPeriod = MealPeriod::factory()->create();
    $ownerPeriod = MealPeriod::factory()->for($owner->property)->create();

    $this->actingAs($frontDesk)
        ->get(route('settings.meal-periods.index', $frontDesk->property->code))
        ->assertForbidden();
    $this->post(route('settings.meal-periods.store', $frontDesk->property->code), mealPeriodPayload())
        ->assertForbidden();
    $this->actingAs($owner)
        ->put(route('settings.meal-periods.update', [$owner->property->code, $otherPeriod]), mealPeriodPayload())
        ->assertNotFound();
    $this->put(route('settings.meal-periods.update', [$owner->property->code, $ownerPeriod]), mealPeriodPayload(['name' => 'Tea']))
        ->assertSessionHasNoErrors();
});

test('the database enforces meal period invariants', function (array $attributes) {
    $property = Property::factory()->create();

    DB::table('meal_periods')->insert([
        'property_id' => $property->id,
        'name' => 'Tea',
        'starts_at' => '15:00',
        'ends_at' => '16:00',
        'active' => true,
        'created_at' => now(),
        'updated_at' => now(),
        ...$attributes,
    ]);
})->throws(QueryException::class)->with([
    'end after start' => [['starts_at' => '16:00', 'ends_at' => '15:00']],
    'end differs from start' => [['starts_at' => '16:00', 'ends_at' => '16:00']],
    'trimmed name' => [['name' => ' Tea ']],
    'non-empty name' => [['name' => '']],
]);

test('meal period names are unique per property ignoring case', function () {
    $property = Property::factory()->create();
    MealPeriod::factory()->for($property)->create(['name' => 'Tea']);

    expect(fn () => MealPeriod::factory()->for($property)->create(['name' => 'tEa']))
        ->toThrow(QueryException::class);
});
