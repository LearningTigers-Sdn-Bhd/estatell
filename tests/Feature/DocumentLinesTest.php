<?php

use App\Enums\TransactionKind;
use App\Models\PrintSetting;
use App\Services\Folio\DocumentLineGroups;
use App\Services\Folio\DocumentLines;
use App\Services\Folio\DocumentRow;
use Carbon\CarbonImmutable;

function documentRow(TransactionKind $kind, string $description, string $date, string $amount, ?int $unitId = null, ?int $lineId = null, ?int $reversalOfId = null, ?string $unitAmount = null, bool $nightly = false): DocumentRow
{
    return new DocumentRow($kind, $description, CarbonImmutable::parse($date), 1, $unitAmount ?? $amount, $amount, $unitId, $unitId === null ? null : "Unit {$unitId}", $lineId, $reversalOfId, $nightly);
}

function room(string $date, string $amount = '250.00', int $unit = 1, ?int $lineId = null): DocumentRow
{
    return documentRow(TransactionKind::Charge, 'Room', $date, $amount, $unit, $lineId, nightly: true);
}

function printSettings(array $attributes = []): PrintSetting
{
    return new PrintSetting(['show_unit_on_lines' => true, 'nightly_breakdown' => false, 'tax_breakdown' => true, ...$attributes]);
}

function group(array $rows, array $settings = []): DocumentLineGroups
{
    return app(DocumentLines::class)->group($rows, printSettings($settings));
}

test('a line and its reversal are dropped together and a transfer shows once', function () {
    $groups = group([
        room('2026-10-12', lineId: 1),
        documentRow(TransactionKind::Charge, 'Room', '2026-10-12', '-250.00', 1, 2, 1),
        documentRow(TransactionKind::Charge, 'Room', '2026-10-12', '250.00', 1, 3),
        documentRow(TransactionKind::Charge, 'Minibar', '2026-10-13', '40.00', 1, 4),
    ]);

    expect($groups->groups)->toHaveCount(1)
        ->and(collect($groups->groups[0]->rows)->map->text()->all())->toBe(['Room', 'Minibar'])
        ->and($groups->total)->toBe('290.00');
});

test('payments are dropped', function () {
    $groups = group([
        room('2026-10-12'),
        documentRow(TransactionKind::Payment, 'Cash', '2026-10-12', '-100.00'),
    ]);

    expect($groups->total)->toBe('250.00')
        ->and($groups->groups[0]->rows)->toHaveCount(1);
});

test('lines with no unit come last under Other charges', function () {
    $groups = group([
        documentRow(TransactionKind::Charge, 'Late fee', '2026-10-11', '20.00'),
        room('2026-10-12', unit: 2),
        room('2026-10-12', unit: 1),
    ]);

    expect(collect($groups->groups)->map->heading->all())->toBe(['Unit 2', 'Unit 1', 'Other charges']);
});

test('without the unit switch the lines are one list by date', function () {
    $groups = group([
        room('2026-10-13', unit: 2),
        documentRow(TransactionKind::Charge, 'Late fee', '2026-10-11', '20.00'),
        room('2026-10-12', unit: 1),
    ], ['show_unit_on_lines' => false]);

    expect($groups->groups)->toHaveCount(1)
        ->and($groups->groups[0]->heading)->toBeNull()
        ->and(collect($groups->groups[0]->rows)->map(fn ($row) => $row->dateFrom->toDateString())->all())->toBe(['2026-10-11', '2026-10-12', '2026-10-13']);
});

test('equal nights merge into one row with a date range', function () {
    $row = group([room('2026-10-12'), room('2026-10-13'), room('2026-10-14')])->groups[0]->rows[0];

    expect($row->text())->toBe('Room, 3 nights × 250.00')
        ->and($row->amount)->toBe('750.00')
        ->and($row->dateFrom->toDateString())->toBe('2026-10-12')
        ->and($row->dateTo->toDateString())->toBe('2026-10-14');
});

test('a price change or a date gap starts a new row', function () {
    $rows = group([
        room('2026-10-12'), room('2026-10-13'), room('2026-10-14', '300.00'), room('2026-10-16', '300.00'),
    ])->groups[0]->rows;

    expect(collect($rows)->map->text()->all())->toBe(['Room, 2 nights × 250.00', 'Room', 'Room']);
});

test('the nightly breakdown keeps one row per night', function () {
    $rows = group([room('2026-10-12'), room('2026-10-13')], ['nightly_breakdown' => true])->groups[0]->rows;

    expect($rows)->toHaveCount(2);
});

test('nights of two units never merge', function () {
    $groups = group([room('2026-10-12', unit: 1), room('2026-10-13', unit: 2)], ['show_unit_on_lines' => false]);

    expect($groups->groups[0]->rows)->toHaveCount(2);
});

test('taxes are summed per tax or as one total', function () {
    $rows = [
        room('2026-10-12'),
        documentRow(TransactionKind::Tax, 'Service tax 8%', '2026-10-12', '20.00'),
        documentRow(TransactionKind::Tax, 'Tourism tax', '2026-10-12', '10.00'),
        documentRow(TransactionKind::Tax, 'Service tax 8%', '2026-10-13', '20.00'),
    ];

    $perTax = group($rows);
    $single = group($rows, ['tax_breakdown' => false]);

    expect(collect($perTax->taxes)->map(fn ($tax) => [$tax->label, $tax->amount])->all())->toBe([['Service tax 8%', '40.00'], ['Tourism tax', '10.00']])
        ->and(collect($single->taxes)->map(fn ($tax) => [$tax->label, $tax->amount])->all())->toBe([['Tax', '50.00']])
        ->and($perTax->chargesSubtotal)->toBe('250.00')
        ->and($perTax->total)->toBe('300.00')
        ->and($single->total)->toBe('300.00');
});

test('a discount merges like a room and lowers the total', function () {
    $groups = group([
        room('2026-10-12'), room('2026-10-13'),
        documentRow(TransactionKind::Adjustment, 'Early bird', '2026-10-12', '-25.00', 1, nightly: true),
        documentRow(TransactionKind::Adjustment, 'Early bird', '2026-10-13', '-25.00', 1, nightly: true),
    ]);

    expect(collect($groups->groups[0]->rows)->map->text()->all())->toBe(['Room, 2 nights × 250.00', 'Early bird, 2 nights × -25.00'])
        ->and($groups->chargesSubtotal)->toBe('450.00');
});

test('only nightly rows merge', function () {
    $laundry = fn (string $date) => documentRow(TransactionKind::Charge, 'Laundry', $date, '15.00', 1);
    $breakfast = fn (string $date) => documentRow(TransactionKind::Charge, 'Breakfast', $date, '20.00', 1, nightly: true);

    $rows = group([$laundry('2026-10-12'), $laundry('2026-10-13'), $breakfast('2026-10-12'), $breakfast('2026-10-13')])->groups[0]->rows;

    expect(collect($rows)->map->text()->all())->toBe(['Laundry', 'Laundry', 'Breakfast, 2 nights × 20.00']);
});
