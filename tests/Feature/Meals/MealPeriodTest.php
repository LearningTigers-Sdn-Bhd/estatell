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

function seededMealPeriodProperty(): array
{
    $owner = User::factory()->owner()->create();
    app(SeedMealPeriods::class)($owner->property, 'MY');

    return [$owner, $owner->property];
}

/**
 * The form payload for the property's saved periods, with the given changes applied by name.
 *
 * @param  array<string, array<string, mixed>>  $changes  Keyed by the period's current name.
 * @param  list<array<string, mixed>>  $extra  New rows to append.
 * @return array{meal_periods: list<array<string, mixed>>}
 */
function mealPeriodsPayload(Property $property, array $changes = [], array $extra = []): array
{
    $rows = $property->mealPeriods()->ordered()->get()
        ->map(fn (MealPeriod $period): array => array_merge([
            'id' => $period->id,
            'name' => $period->name,
            'starts_at' => substr($period->starts_at, 0, 5),
            'ends_at' => substr($period->ends_at, 0, 5),
            'active' => $period->active,
        ], $changes[$period->name] ?? []))
        ->all();

    return ['meal_periods' => [...$rows, ...$extra]];
}

/** @return array<string, mixed> */
function newMealPeriod(array $overrides = []): array
{
    return array_merge(['id' => null, 'name' => 'Hi-tea', 'starts_at' => '15:30', 'ends_at' => '17:00', 'active' => true], $overrides);
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

    app(SeedMealPeriods::class)($property);
    $property->mealPeriods()->where('name', 'Lunch')->update(['name' => 'Midday meal', 'active' => false]);
    app(SeedMealPeriods::class)($property);

    $periods = $property->mealPeriods()->ordered()->get();
    expect($periods->pluck('name')->all())->toBe(['Breakfast', 'Midday meal', 'Dinner'])
        ->and($periods->pluck('starts_at')->all())->toBe(['07:00:00', '12:00:00', '19:00:00'])
        ->and($periods->pluck('ends_at')->all())->toBe(['10:00:00', '14:30:00', '22:00:00'])
        ->and($periods->firstWhere('name', 'Midday meal')->active)->toBeFalse();
});

test('the tab lists this property\'s periods by start time and opens under the property settings navigation entry', function () {
    [$owner, $property] = seededMealPeriodProperty();
    MealPeriod::factory()->for($property)->inactive()->create(['name' => 'Supper', 'starts_at' => '22:30', 'ends_at' => '23:30']);
    MealPeriod::factory()->create(['name' => 'Other property']);

    $this->actingAs($owner)
        ->get(route('settings.meal-periods', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/edit')
            ->where('tab', 'meal-periods')
            ->has('mealPeriods', 4)
            ->where('mealPeriods.0.name', 'Breakfast')
            ->where('mealPeriods.0.startsAt', '07:00')
            ->where('mealPeriods.0.endsAt', '10:00')
            ->where('mealPeriods.3.name', 'Supper')
            ->where('navigation.contexts.3.groups.0.items.0.title', 'Details')
            ->where('navigation.contexts.3.groups.0.items.0.active', true)
            ->where('navigation.contexts.3.groups.2.label', 'Rates')
            ->missing('navigation.contexts.3.groups.2.items.1'));
});

test('a new period lands between lunch and dinner by its start time', function () {
    [$owner, $property] = seededMealPeriodProperty();

    $this->actingAs($owner)
        ->put(route('settings.meal-periods.update', $property->code), mealPeriodsPayload($property, extra: [newMealPeriod(['name' => ' Hi-tea '])]))
        ->assertRedirect(route('settings.meal-periods', $property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Meal periods saved.']);

    expect($property->mealPeriods()->ordered()->pluck('name')->all())
        ->toBe(['Breakfast', 'Lunch', 'Hi-tea', 'Dinner'])
        ->and(Activity::query()->where('event', 'meal_periods.updated')->count())->toBe(1);
});

test('periods that only touch another period are accepted', function () {
    [$owner, $property] = seededMealPeriodProperty();

    $this->actingAs($owner)
        ->put(route('settings.meal-periods.update', $property->code), mealPeriodsPayload($property, extra: [newMealPeriod(['starts_at' => '14:30', 'ends_at' => '19:00'])]))
        ->assertSessionHasNoErrors();

    expect($property->mealPeriods()->count())->toBe(4);
});

test('the list is validated', function (array $overrides, string $field) {
    [$owner, $property] = seededMealPeriodProperty();

    $this->actingAs($owner)
        ->put(route('settings.meal-periods.update', $property->code), mealPeriodsPayload($property, extra: [newMealPeriod($overrides)]))
        ->assertSessionHasErrors("meal_periods.3.{$field}");

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

test('two new periods that overlap each other are refused', function () {
    [$owner, $property] = seededMealPeriodProperty();

    $this->actingAs($owner)
        ->put(route('settings.meal-periods.update', $property->code), mealPeriodsPayload($property, extra: [
            newMealPeriod(['name' => 'Tea', 'starts_at' => '15:00', 'ends_at' => '16:00']),
            newMealPeriod(['name' => 'Hi-tea', 'starts_at' => '15:30', 'ends_at' => '17:00']),
        ]))
        ->assertSessionHasErrors('meal_periods.4.ends_at');

    expect($property->mealPeriods()->count())->toBe(3);
});

test('an inactive period does not block an overlapping one', function () {
    [$owner, $property] = seededMealPeriodProperty();

    $this->actingAs($owner)
        ->put(route('settings.meal-periods.update', $property->code), mealPeriodsPayload(
            $property,
            ['Lunch' => ['active' => false]],
            [newMealPeriod(['starts_at' => '12:30', 'ends_at' => '13:30'])],
        ))
        ->assertSessionHasNoErrors();

    expect($property->mealPeriods()->count())->toBe(4);
});

test('an inactive period may overlap when it is saved inactive', function () {
    [$owner, $property] = seededMealPeriodProperty();

    $this->actingAs($owner)
        ->put(route('settings.meal-periods.update', $property->code), mealPeriodsPayload($property, extra: [newMealPeriod(['starts_at' => '12:30', 'ends_at' => '13:30', 'active' => false])]))
        ->assertSessionHasNoErrors();

    expect($property->mealPeriods()->where('active', false)->count())->toBe(1);
});

test('owners edit a period, keep its id and are not blocked by its own old times', function () {
    [$owner, $property] = seededMealPeriodProperty();
    $lunch = $property->mealPeriods()->where('name', 'Lunch')->sole();

    $this->actingAs($owner)
        ->put(route('settings.meal-periods.update', $property->code), mealPeriodsPayload($property, [
            'Lunch' => ['name' => ' Midday meal ', 'starts_at' => '12:30', 'ends_at' => '15:00'],
        ]))
        ->assertSessionHasNoErrors();

    expect($lunch->refresh()->name)->toBe('Midday meal')
        ->and($lunch->starts_at)->toBe('12:30:00')
        ->and($lunch->ends_at)->toBe('15:00:00')
        ->and($property->mealPeriods()->count())->toBe(3)
        ->and(Activity::query()->where('event', 'meal_periods.updated')->count())->toBe(1);
});

test('editing times into another active period is refused', function () {
    [$owner, $property] = seededMealPeriodProperty();
    $lunch = $property->mealPeriods()->where('name', 'Lunch')->sole();

    $this->actingAs($owner)
        ->put(route('settings.meal-periods.update', $property->code), mealPeriodsPayload($property, ['Lunch' => ['ends_at' => '19:30']]))
        ->assertSessionHasErrors('meal_periods.2.ends_at');

    expect($lunch->refresh()->ends_at)->toBe('14:30:00');
});

test('reactivating a period into an overlap is refused', function () {
    [$owner, $property] = seededMealPeriodProperty();
    $property->mealPeriods()->where('name', 'Lunch')->update(['active' => false]);
    MealPeriod::factory()->for($property)->create(['name' => 'Brunch', 'starts_at' => '13:00', 'ends_at' => '14:00']);
    $lunch = $property->mealPeriods()->where('name', 'Lunch')->sole();

    $this->actingAs($owner)
        ->put(route('settings.meal-periods.update', $property->code), mealPeriodsPayload($property, ['Lunch' => ['active' => true]]))
        ->assertSessionHasErrors();

    expect($lunch->refresh()->active)->toBeFalse();
});

test('periods can swap names in one save', function () {
    [$owner, $property] = seededMealPeriodProperty();

    $this->actingAs($owner)
        ->put(route('settings.meal-periods.update', $property->code), mealPeriodsPayload($property, [
            'Lunch' => ['name' => 'Dinner'],
            'Dinner' => ['name' => 'Lunch'],
        ]))
        ->assertSessionHasNoErrors();

    expect($property->mealPeriods()->ordered()->pluck('name')->all())->toBe(['Breakfast', 'Dinner', 'Lunch']);
});

test('owners deactivate a period and no-op saves are not logged', function () {
    [$owner, $property] = seededMealPeriodProperty();
    $lunch = $property->mealPeriods()->where('name', 'Lunch')->sole();
    $url = route('settings.meal-periods.update', $property->code);

    $this->actingAs($owner)->put($url, mealPeriodsPayload($property));
    expect(Activity::query()->where('event', 'meal_periods.updated')->count())->toBe(0);

    $this->put($url, mealPeriodsPayload($property, ['Lunch' => ['active' => false]]));
    $this->put($url, mealPeriodsPayload($property, ['Lunch' => ['active' => false]]));

    expect($lunch->refresh()->active)->toBeFalse()
        ->and(Activity::query()->where('event', 'meal_periods.updated')->count())->toBe(1);
});

test('a list that leaves out a saved period is refused', function () {
    [$owner, $property] = seededMealPeriodProperty();
    $payload = mealPeriodsPayload($property);
    array_pop($payload['meal_periods']);

    $this->actingAs($owner)
        ->put(route('settings.meal-periods.update', $property->code), $payload)
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The meal periods changed. Reload the page and try again.']);

    expect($property->mealPeriods()->count())->toBe(3)
        ->and(Activity::query()->where('event', 'meal_periods.updated')->count())->toBe(0);
});

test('routes enforce settings permission and property scoping', function () {
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    $owner = User::factory()->owner()->create();
    $otherPeriod = MealPeriod::factory()->create();

    $this->actingAs($frontDesk)
        ->get(route('settings.meal-periods', $frontDesk->property->code))
        ->assertForbidden();
    $this->put(route('settings.meal-periods.update', $frontDesk->property->code), ['meal_periods' => []])
        ->assertForbidden();
    $this->actingAs($owner)
        ->put(route('settings.meal-periods.update', $owner->property->code), ['meal_periods' => [newMealPeriod(['id' => $otherPeriod->id])]])
        ->assertSessionHasErrors('meal_periods.0.id');
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
