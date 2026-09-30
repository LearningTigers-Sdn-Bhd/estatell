<?php

use App\Data\Meals\MealEntitlementData;
use App\Data\Meals\MealInclusionData;
use App\Enums\InclusionEnd;
use App\Enums\InclusionStart;
use App\Services\Meals\MealEntitlementResolver;
use Carbon\CarbonImmutable;

function mealInclusion(int $periodId, string $startsAt, InclusionStart $start, InclusionEnd $end): MealInclusionData
{
    return new MealInclusionData($periodId, $startsAt, $start, $end);
}

function breakfast(): MealInclusionData
{
    return mealInclusion(1, '07:00', InclusionStart::DayAfterArrival, InclusionEnd::DepartureDay);
}

function dinner(): MealInclusionData
{
    return mealInclusion(3, '19:00', InclusionStart::ArrivalDay, InclusionEnd::NightBeforeDeparture);
}

/**
 * @param  iterable<MealEntitlementData>  $rows
 * @return list<string>
 */
function mealRows(iterable $rows): array
{
    return collect($rows)
        ->map(fn (MealEntitlementData $row): string => "{$row->date} #{$row->mealPeriodId} x{$row->guests}")
        ->all();
}

function resolveMeals(string $arrival, string $departure, int $guests, array $inclusions): array
{
    return mealRows(app(MealEntitlementResolver::class)->resolve(
        CarbonImmutable::parse($arrival),
        CarbonImmutable::parse($departure),
        $guests,
        $inclusions,
    ));
}

test('inclusion start and end enums pick their dates and expose labels and options', function () {
    $arrival = CarbonImmutable::parse('2026-10-01 14:00');
    $departure = CarbonImmutable::parse('2026-10-03 11:00');

    expect(InclusionStart::ArrivalDay->firstDate($arrival)->toDateString())->toBe('2026-10-01')
        ->and(InclusionStart::DayAfterArrival->firstDate($arrival)->toDateString())->toBe('2026-10-02')
        ->and(InclusionEnd::DepartureDay->lastDate($departure)->toDateString())->toBe('2026-10-03')
        ->and(InclusionEnd::NightBeforeDeparture->lastDate($departure)->toDateString())->toBe('2026-10-02')
        ->and(InclusionStart::DayAfterArrival->label())->toBe('Day after arrival')
        ->and(InclusionEnd::NightBeforeDeparture->label())->toBe('Night before departure')
        ->and(InclusionStart::options())->toBe([
            ['value' => 'arrival_day', 'label' => 'Arrival day'],
            ['value' => 'day_after_arrival', 'label' => 'Day after arrival'],
        ])
        ->and(InclusionEnd::options())->toBe([
            ['value' => 'departure_day', 'label' => 'Departure day'],
            ['value' => 'night_before_departure', 'label' => 'Night before departure'],
        ]);
});

test('breakfast and dinner follow the default windows and sort by date then meal time', function () {
    expect(resolveMeals('2026-10-01', '2026-10-03', 2, [dinner(), breakfast()]))->toBe([
        '2026-10-01 #3 x2',
        '2026-10-02 #1 x2',
        '2026-10-02 #3 x2',
        '2026-10-03 #1 x2',
    ]);
});

test('a one-night stay gets dinner on arrival and breakfast on departure', function () {
    expect(resolveMeals('2026-10-01', '2026-10-02', 1, [breakfast(), dinner()]))->toBe([
        '2026-10-01 #3 x1',
        '2026-10-02 #1 x1',
    ]);
});

test('a same-day booking with only dinner serves nothing', function () {
    expect(resolveMeals('2026-10-01', '2026-10-01', 2, [dinner()]))->toBe([]);
});

test('a full board package with hi-tea lists every period in meal order', function () {
    $lunch = mealInclusion(2, '12:00', InclusionStart::DayAfterArrival, InclusionEnd::NightBeforeDeparture);
    $hiTea = mealInclusion(9, '15:30', InclusionStart::ArrivalDay, InclusionEnd::DepartureDay);

    expect(resolveMeals('2026-10-01', '2026-10-03', 3, [dinner(), $hiTea, $lunch, breakfast()]))->toBe([
        '2026-10-01 #9 x3',
        '2026-10-01 #3 x3',
        '2026-10-02 #1 x3',
        '2026-10-02 #2 x3',
        '2026-10-02 #9 x3',
        '2026-10-02 #3 x3',
        '2026-10-03 #1 x3',
        '2026-10-03 #9 x3',
    ]);
});

test('two inclusions for one period merge their dates and never double the count', function () {
    $early = mealInclusion(1, '07:00', InclusionStart::ArrivalDay, InclusionEnd::NightBeforeDeparture);

    expect(resolveMeals('2026-10-01', '2026-10-03', 2, [$early, breakfast()]))->toBe([
        '2026-10-01 #1 x2',
        '2026-10-02 #1 x2',
        '2026-10-03 #1 x2',
    ]);
});

test('every guest counts, and no guests or no inclusions give no rows', function () {
    expect(resolveMeals('2026-10-01', '2026-10-02', 5, [dinner()]))->toBe(['2026-10-01 #3 x5'])
        ->and(resolveMeals('2026-10-01', '2026-10-03', 0, [breakfast()]))->toBe([])
        ->and(resolveMeals('2026-10-01', '2026-10-03', 2, []))->toBe([]);
});

test('an inclusion window that starts after it ends serves nothing', function () {
    $backwards = mealInclusion(4, '10:30', InclusionStart::DayAfterArrival, InclusionEnd::NightBeforeDeparture);

    expect(resolveMeals('2026-10-01', '2026-10-02', 2, [$backwards]))->toBe([]);
});

test('arrival and departure times of day are ignored', function () {
    expect(resolveMeals('2026-10-01 23:30', '2026-10-02 00:15', 1, [breakfast(), dinner()]))->toBe([
        '2026-10-01 #3 x1',
        '2026-10-02 #1 x1',
    ]);
});
