<?php

use App\Enums\ChargingUnit;
use App\Models\ReservationUnit;
use App\Services\Catalogues\ChargeQuantityResolver;
use Carbon\CarbonImmutable;

/**
 * @param  list<array{service_date: CarbonImmutable|null, quantity: int}>  $rows
 * @return list<array{string|null, int}>
 */
function resolvedRows(array $rows): array
{
    return array_map(fn (array $row): array => [$row['service_date']?->toDateString(), $row['quantity']], $rows);
}

/**
 * Three nights from 2026-11-02 for 2 adults and 1 child.
 */
function resolverUnit(): ReservationUnit
{
    return new ReservationUnit(['arrival_date' => '2026-11-02', 'departure_date' => '2026-11-05', 'adults' => 2, 'children' => 1]);
}

test('per night gives one row per night of quantity one and ignores the input', function () {
    $rows = (new ChargeQuantityResolver)->rows(ChargingUnit::PerNight, resolverUnit(), CarbonImmutable::parse('2026-10-01'), 7, CarbonImmutable::parse('2026-11-03'));

    expect(resolvedRows($rows))->toBe([['2026-11-02', 1], ['2026-11-03', 1], ['2026-11-04', 1]]);
});

test('per guest night gives adults plus children on each night', function () {
    $rows = (new ChargeQuantityResolver)->rows(ChargingUnit::PerGuestNight, resolverUnit(), CarbonImmutable::parse('2026-10-01'));

    expect(resolvedRows($rows))->toBe([['2026-11-02', 3], ['2026-11-03', 3], ['2026-11-04', 3]]);
});

test('nightly units skip nights before the business date', function () {
    $resolver = new ChargeQuantityResolver;

    expect(resolvedRows($resolver->rows(ChargingUnit::PerNight, resolverUnit(), CarbonImmutable::parse('2026-11-04'))))->toBe([['2026-11-04', 1]])
        ->and($resolver->rows(ChargingUnit::PerNight, resolverUnit(), CarbonImmutable::parse('2026-11-05')))->toBe([]);
});

test('per item keeps the entered quantity and date, and defaults to quantity one', function () {
    $resolver = new ChargeQuantityResolver;
    $date = CarbonImmutable::parse('2026-11-03');

    expect(resolvedRows($resolver->rows(ChargingUnit::PerItem, resolverUnit(), CarbonImmutable::parse('2026-10-01'), 4, $date)))->toBe([['2026-11-03', 4]])
        ->and(resolvedRows($resolver->rows(ChargingUnit::PerItem, resolverUnit(), CarbonImmutable::parse('2026-10-01'))))->toBe([[null, 1]]);
});

test('per stay and per guest give one row with the optional date', function () {
    $resolver = new ChargeQuantityResolver;
    $date = CarbonImmutable::parse('2026-11-03');

    expect(resolvedRows($resolver->rows(ChargingUnit::PerStay, resolverUnit(), CarbonImmutable::parse('2026-10-01'), 9, $date)))->toBe([['2026-11-03', 1]])
        ->and(resolvedRows($resolver->rows(ChargingUnit::PerGuest, resolverUnit(), CarbonImmutable::parse('2026-10-01'))))->toBe([[null, 3]]);
});
