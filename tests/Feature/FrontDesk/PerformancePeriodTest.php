<?php

use App\Enums\PerformancePreset;
use App\Services\Dashboard\PerformancePeriod;
use Carbon\CarbonImmutable;

/**
 * @return array{string, string, string, string}
 */
function periodDates(PerformancePeriod $period): array
{
    return [
        $period->from->toDateString(),
        $period->to->toDateString(),
        $period->previousFrom->toDateString(),
        $period->previousTo->toDateString(),
    ];
}

function periodFor(?string $period, ?string $month = null, string $date = '2026-10-18', ?string $earliest = '2026-01-10'): PerformancePeriod
{
    return PerformancePeriod::fromRequest($period, $month, CarbonImmutable::parse($date), $earliest === null ? null : CarbonImmutable::parse($earliest));
}

test('each preset builds its range and comparison from the business date', function (string $preset, string $date, array $expected) {
    $period = periodFor($preset, date: $date);

    expect(periodDates($period))->toBe($expected);
})->with([
    'last 7 days' => ['7d', '2026-10-18', ['2026-10-12', '2026-10-18', '2026-10-05', '2026-10-11']],
    'last 30 days' => ['30d', '2026-10-18', ['2026-09-19', '2026-10-18', '2026-08-20', '2026-09-18']],
    'this month' => ['month', '2026-10-18', ['2026-10-01', '2026-10-18', '2026-09-01', '2026-09-18']],
    'this month on the 31st ends the comparison on 30 Sep' => ['month', '2026-10-31', ['2026-10-01', '2026-10-31', '2026-09-01', '2026-09-30']],
    'this month on 31 Mar ends the comparison on 28 Feb' => ['month', '2026-03-31', ['2026-03-01', '2026-03-31', '2026-02-01', '2026-02-28']],
    'last month' => ['last-month', '2026-10-18', ['2026-09-01', '2026-09-30', '2026-08-01', '2026-08-31']],
    'last month across a year end' => ['last-month', '2026-01-05', ['2025-12-01', '2025-12-31', '2025-11-01', '2025-11-30']],
    'last 12 months against the same range a year earlier' => ['12m', '2026-10-18', ['2025-11-01', '2026-10-18', '2024-11-01', '2025-10-18']],
    'last 12 months on a leap day' => ['12m', '2028-02-29', ['2027-03-01', '2028-02-29', '2026-03-01', '2027-02-28']],
]);

test('the range ends on the business date, never later', function () {
    foreach (PerformancePreset::cases() as $preset) {
        expect(periodFor($preset->value)->to->lte(CarbonImmutable::parse('2026-10-18')))->toBeTrue();
    }
});

test('only the 12 month range groups by month', function () {
    expect(periodFor('12m')->groupsByMonth)->toBeTrue()
        ->and(periodFor('30d')->groupsByMonth)->toBeFalse()
        ->and(periodFor(null, '2026-09')->groupsByMonth)->toBeFalse();
});

test('a picked whole month is compared with the month before', function () {
    $period = periodFor(null, '2026-09');

    expect($period->preset)->toBeNull()
        ->and($period->month)->toBe('2026-09')
        ->and(periodDates($period))->toBe(['2026-09-01', '2026-09-30', '2026-08-01', '2026-08-31']);
});

test('a bad value falls back to this month', function (?string $period, ?string $month, ?string $earliest = '2026-01-10') {
    $result = periodFor($period, $month, earliest: $earliest);

    expect($result->preset)->toBe(PerformancePreset::Month)
        ->and(periodDates($result))->toBe(['2026-10-01', '2026-10-18', '2026-09-01', '2026-09-18']);
})->with([
    'unknown period' => ['yearly', null],
    'empty' => [null, null],
    'unparsable month' => [null, 'september'],
    'month without a leading zero' => [null, '2026-9'],
    'month 13' => [null, '2026-13'],
    'the current month' => [null, '2026-10'],
    'a future month' => [null, '2026-11'],
    'a month before the earliest data' => [null, '2025-12'],
    'no data at all' => [null, '2026-09', null],
]);

test('a picked month wins over the period', function () {
    expect(periodFor('7d', '2026-08')->month)->toBe('2026-08');
});

test('the picker offers whole months from the earliest month with data to last month, newest first', function () {
    $date = CarbonImmutable::parse('2026-10-18');

    expect(PerformancePeriod::pickableMonths($date, CarbonImmutable::parse('2026-07-20')))->toBe(['2026-09', '2026-08', '2026-07'])
        ->and(PerformancePeriod::pickableMonths($date, CarbonImmutable::parse('2026-10-02')))->toBe([])
        ->and(PerformancePeriod::pickableMonths($date, null))->toBe([])
        ->and(PerformancePeriod::pickableMonths(CarbonImmutable::parse('2026-01-03'), CarbonImmutable::parse('2025-11-30')))->toBe(['2025-12', '2025-11']);
});
