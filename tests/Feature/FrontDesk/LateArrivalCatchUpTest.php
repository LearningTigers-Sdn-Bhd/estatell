<?php

use App\Actions\FrontDesk\CheckInUnit;
use App\Enums\UnitStatus;
use App\Models\FolioLine;
use Carbon\CarbonImmutable;

require_once __DIR__.'/../Support/PastStaySetup.php';

function nightLinesOf(int $unitId): array
{
    return FolioLine::query()
        ->whereIn('idempotency_key', DB::table('unit_nights')->where('reservation_unit_id', $unitId)->pluck('id')->map(fn ($id): string => 'night:'.$id)->all())
        ->whereNull('parent_line_id')
        ->orderBy('service_date')
        ->get(['service_date', 'posting_date'])
        ->map(fn ($line): array => [$line->service_date->toDateString(), $line->posting_date->toDateString()])
        ->all();
}

test('a late arrival is charged the nights it missed when it checks in', function () {
    $setup = pastStaySetup();
    $unit = pastStayUnit($setup, '2026-10-12', '2026-10-16');

    $result = app(CheckInUnit::class)($setup['user'], $unit);

    expect($result->isRefused())->toBeFalse()
        ->and(nightLinesOf($unit->id))->toBe([['2026-10-12', '2026-10-14'], ['2026-10-13', '2026-10-14']]);
});

test('a unit that arrives on the business date gets no catch-up lines', function () {
    $setup = pastStaySetup();
    $unit = pastStayUnit($setup, '2026-10-14', '2026-10-16');

    app(CheckInUnit::class)($setup['user'], $unit);

    expect(nightLinesOf($unit->id))->toBe([]);
});

test('asOf sets the moment of the check-in', function () {
    $setup = pastStaySetup();
    $unit = pastStayUnit($setup, '2026-10-12', '2026-10-16');

    app(CheckInUnit::class)($setup['user'], $unit, asOf: CarbonImmutable::parse('2026-10-12 15:00'));

    $unit->refresh();

    expect($unit->status)->toBe(UnitStatus::CheckedIn)
        ->and($unit->checked_in_at->format('Y-m-d H:i'))->toBe('2026-10-12 15:00');
});
