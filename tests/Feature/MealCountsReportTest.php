<?php

use App\Enums\DefaultRoleEnum;
use App\Enums\InclusionEnd;
use App\Enums\InclusionStart;
use App\Enums\ReportKey;
use App\Features\Boats;
use App\Models\BusinessDate;
use App\Models\MealPeriod;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\RatePlan;
use App\Models\RatePlanInclusion;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\User;
use Illuminate\Support\Facades\View;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * @return array{0: User, 1: Property}
 */
function mealCountsSetup(DefaultRoleEnum $role = DefaultRoleEnum::Manager): array
{
    $user = User::factory()->withRole($role)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-10']);

    return [$user, $property];
}

function mealCountsUrl(Property $property, string $query = ''): string
{
    return "/p/{$property->code}/reports/meal-counts".$query;
}

/**
 * A rate plan with dinner (arrival day to the night before departure) and breakfast (day after arrival to departure
 * day). Breakfast is included twice, to prove that one period never counts a guest twice.
 *
 * @return array{plan: RatePlan, breakfast: MealPeriod, dinner: MealPeriod}
 */
function mealCountsPlan(Property $property): array
{
    $breakfast = MealPeriod::factory()->create(['property_id' => $property->id, 'name' => 'Breakfast', 'starts_at' => '07:00']);
    $dinner = MealPeriod::factory()->create(['property_id' => $property->id, 'name' => 'Dinner', 'starts_at' => '19:00', 'ends_at' => '20:00']);
    $plan = RatePlan::factory()->create(['property_id' => $property->id]);
    RatePlanInclusion::factory()->create(['rate_plan_id' => $plan->id, 'meal_period_id' => $dinner->id, 'starts_on' => InclusionStart::ArrivalDay, 'ends_on' => InclusionEnd::NightBeforeDeparture]);
    RatePlanInclusion::factory()->create(['rate_plan_id' => $plan->id, 'meal_period_id' => $breakfast->id, 'starts_on' => InclusionStart::DayAfterArrival, 'ends_on' => InclusionEnd::DepartureDay]);
    RatePlanInclusion::factory()->create(['rate_plan_id' => $plan->id, 'meal_period_id' => $breakfast->id, 'starts_on' => InclusionStart::DayAfterArrival, 'ends_on' => InclusionEnd::DepartureDay]);

    return ['plan' => $plan, 'breakfast' => $breakfast, 'dinner' => $dinner];
}

function mealCountsUnit(Property $property, RatePlan $plan, string $state, string $arrival, string $departure, int $adults, int $children = 0): ReservationUnit
{
    $factory = ReservationUnit::factory()->for(Reservation::factory()->create(['property_id' => $property->id]));

    return ($state === 'confirmed' ? $factory : $factory->{$state}())
        ->create(['rate_plan_id' => $plan->id, 'arrival_date' => $arrival, 'departure_date' => $departure, 'adults' => $adults, 'children' => $children]);
}

/**
 * A checked-out stay that left early, a confirmed stay, and a cancelled and a no-show stay that count for nothing.
 *
 * @return array{breakfast: MealPeriod, dinner: MealPeriod}
 */
function mealCountsData(Property $property): array
{
    ['plan' => $plan, 'breakfast' => $breakfast, 'dinner' => $dinner] = mealCountsPlan($property);
    mealCountsUnit($property, $plan, 'checkedOut', '2026-10-08', '2026-10-10', 2, 1);
    mealCountsUnit($property, $plan, 'confirmed', '2026-10-10', '2026-10-12', 1);
    mealCountsUnit($property, $plan, 'cancelled', '2026-10-09', '2026-10-11', 5, 5);
    mealCountsUnit($property, $plan, 'noShow', '2026-10-09', '2026-10-11', 5, 5);

    return ['breakfast' => $breakfast, 'dinner' => $dinner];
}

test('the page needs reports.view, is scoped to the property and sends its layout and presets', function () {
    [$front, $frontProperty] = mealCountsSetup(DefaultRoleEnum::FrontDesk);
    [$manager, $own] = mealCountsSetup();
    [, $other] = mealCountsSetup();
    mealCountsData($own);

    $this->actingAs($front)->get(mealCountsUrl($frontProperty))->assertForbidden();
    $this->actingAs($manager)->get(mealCountsUrl($other))->assertNotFound();
    $this->actingAs($manager)->get(mealCountsUrl($own, '?from=2026-10-09&to=2026-10-10'))->assertInertia(fn (Assert $page) => $page
        ->component('reports/meal-counts')
        ->where('maxRangeDays', 31)
        ->where('sort', 'date')
        ->where('presets', ['today', 'tomorrow', 'next-7-days', 'this-week', 'next-week'])
        ->where('hasMealPlans', true)
        ->has('layout.columns', 5)
        ->where('rows.meta.total', 4));

    expect(ReportKey::Arrivals->datePresets())->toBeNull();
});

test('rows split adults and children, count each period once and keep the meal start order within a date', function () {
    [$user, $property] = mealCountsSetup();
    ['breakfast' => $breakfast, 'dinner' => $dinner] = mealCountsData($property);

    $this->actingAs($user)->get(mealCountsUrl($property, '?from=2026-10-09&to=2026-10-10'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.data', fn ($rows) => collect($rows)->map(fn ($row) => [$row['key'], $row['meal'], $row['adults'], $row['children'], $row['total']])->all() === [
            ["2026-10-09:{$breakfast->id}", 'Breakfast', 2, 1, 3],
            ["2026-10-09:{$dinner->id}", 'Dinner', 2, 1, 3],
            ["2026-10-10:{$breakfast->id}", 'Breakfast', 2, 1, 3],
            ["2026-10-10:{$dinner->id}", 'Dinner', 1, 0, 1],
        ]));
});

test('checked-out stays count on past dates and cancelled and no-show stays never count', function () {
    [$user, $property] = mealCountsSetup();
    ['dinner' => $dinner] = mealCountsData($property);

    $this->actingAs($user)->get(mealCountsUrl($property, '?from=2026-10-08&to=2026-10-08'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.data', fn ($rows) => collect($rows)->map(fn ($row) => [$row['key'], $row['adults'], $row['children']])->all() === [
            ["2026-10-08:{$dinner->id}", 2, 1],
        ])
        ->where('totals.figures.total', 3));
});

test('future dates are counted and the totals row sums adults, children and guests', function () {
    [$user, $property] = mealCountsSetup();
    mealCountsData($property);

    $this->actingAs($user)->get(mealCountsUrl($property, '?from=2026-10-09&to=2026-10-12'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.meta.total', 7)
        ->where('totals.figures', ['date' => null, 'meal' => null, 'adults' => 10, 'children' => 3, 'total' => 13]));
});

test('another property sees none of these meals and is told when no plan includes meals', function () {
    [$user, $property] = mealCountsSetup();
    [$otherUser, $other] = mealCountsSetup();
    mealCountsData($property);

    $this->actingAs($otherUser)->get(mealCountsUrl($other, '?from=2026-10-08&to=2026-10-12'))->assertInertia(fn (Assert $page) => $page
        ->where('hasMealPlans', false)
        ->where('rows.meta.total', 0)
        ->where('totals.figures.total', 0));
});

test('a range of more than 31 days is refused', function () {
    [$user, $property] = mealCountsSetup();

    $this->actingAs($user)->get(mealCountsUrl($property, '?from=2026-10-01&to=2026-11-01'))->assertSessionHasErrors('to');
    $this->actingAs($user)->get(mealCountsUrl($property, '?from=2026-10-01&to=2026-10-31'))->assertSessionHasNoErrors();
});

test('the PDF and CSV carry only the selected rows and their totals', function () {
    [$user, $property] = mealCountsSetup();
    ['breakfast' => $breakfast, 'dinner' => $dinner] = mealCountsData($property);
    $base = ['from' => '2026-10-09', 'to' => '2026-10-10', 'rowKeys' => ["2026-10-09:{$dinner->id}", "2026-10-10:{$breakfast->id}"]];
    $captured = new stdClass;
    View::composer('pdf.report', function ($view) use ($captured): void {
        $captured->data = $view->getData();
    });

    $this->actingAs($user)->post("/p/{$property->code}/reports/meal-counts/pdf", $base)->assertOk();
    expect($captured->data['rows'])->toHaveCount(2)
        ->and($captured->data['totals']->figures)->toBe(['date' => null, 'meal' => null, 'adults' => 4, 'children' => 2, 'total' => 6]);

    $response = $this->actingAs($user)->post("/p/{$property->code}/reports/meal-counts/spreadsheet", [...$base, 'format' => 'csv']);
    $rows = array_map('str_getcsv', array_values(array_filter(explode("\n", ltrim($response->streamedContent(), "\xEF\xBB\xBF")))));

    expect($rows)->toHaveCount(4)
        ->and($rows[0])->toBe(['Date', 'Meal', 'Adults', 'Children', 'Total'])
        ->and($rows[1])->toBe(['2026-10-09', 'Dinner', '2', '1', '3'])
        ->and(end($rows))->toBe(['Total', '', '4', '2', '6']);
});

test('with boats on, a late arrival and an early departure trim the dinner and breakfast counts, and with boats off they do not', function () {
    [$user, $property] = mealCountsSetup();
    ['plan' => $plan, 'breakfast' => $breakfast, 'dinner' => $dinner] = mealCountsPlan($property);
    $unit = mealCountsUnit($property, $plan, 'confirmed', '2026-10-11', '2026-10-12', 2);
    $unit->forceFill(['arrival_time' => '20:30:00', 'departure_time' => '06:30:00'])->save();
    $url = mealCountsUrl($property, '?from=2026-10-11&to=2026-10-12');

    $this->actingAs($user)->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('rows.data', fn ($rows) => collect($rows)->pluck('key')->all() === ["2026-10-11:{$dinner->id}", "2026-10-12:{$breakfast->id}"]));

    Feature::for($property)->activate(Boats::class);

    $this->actingAs($user)->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('rows.data', fn ($rows) => collect($rows)->pluck('key')->all() === []));
});
