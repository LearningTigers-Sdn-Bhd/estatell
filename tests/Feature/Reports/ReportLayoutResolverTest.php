<?php

use App\Data\Reports\ReportColumn;
use App\Enums\ColumnAlign;
use App\Enums\ReportKey;
use App\Models\ReportLayout;
use App\Models\ReportLayoutColumn;
use App\Services\Reports\ReportLayoutResolver;

/**
 * @param  list<string>  $keys
 */
function storedLayout(array $keys, ReportKey $report = ReportKey::DailyRevenue): ReportLayout
{
    $layout = ReportLayout::factory()->create(['report_key' => $report]);

    foreach ($keys as $position => $key) {
        ReportLayoutColumn::factory()->create(['report_layout_id' => $layout->id, 'column_key' => $key, 'position' => $position]);
    }

    return $layout->load('columns');
}

/**
 * @param  list<string>  $keys
 * @return list<ReportColumn>
 */
function codeColumns(array $keys): array
{
    return array_map(fn (string $key): ReportColumn => new ReportColumn($key, $key, ColumnAlign::Right, true), $keys);
}

/**
 * @return list<string>
 */
function resolvedKeys(ReportLayout $layout, array $codeKeys): array
{
    return array_map(fn ($column): string => $column->key, (new ReportLayoutResolver)->merge($layout, codeColumns($codeKeys))->columns);
}

test('a new tax column goes before the stored adjustments column, several in code order', function () {
    $layout = storedLayout(['period', 'fees', 'adjustments', 'total']);

    expect(resolvedKeys($layout, ['period', 'fees', 'tax:SST', 'tax:TTX', 'adjustments', 'total']))
        ->toBe(['period', 'fees', 'tax:SST', 'tax:TTX', 'adjustments', 'total']);
});

test('a new tax column goes last when the layout has no adjustments column', function () {
    $layout = storedLayout(['period', 'fees']);

    expect(resolvedKeys($layout, ['period', 'fees', 'tax:SST', 'adjustments']))->toBe(['period', 'fees', 'tax:SST', 'adjustments']);
});

test('other new columns still go last and stored tax columns keep their place', function () {
    $layout = storedLayout(['tax:SST', 'period', 'adjustments', 'gone:x']);

    expect(resolvedKeys($layout, ['period', 'tax:SST', 'tax:TTX', 'adjustments', 'notes']))
        ->toBe(['tax:SST', 'period', 'tax:TTX', 'adjustments', 'notes']);
});

test('a new tax column goes before the stored total column on Daily charges', function () {
    $layout = storedLayout(['date', 'base', 'total', 'staff'], ReportKey::DailyCharges);

    expect(resolvedKeys($layout, ['date', 'base', 'tax:SST', 'tax:TTX', 'adjustments', 'total', 'staff']))
        ->toBe(['date', 'base', 'tax:SST', 'tax:TTX', 'total', 'staff', 'adjustments']);
});

test('a report without a tax anchor puts a new tax column last', function () {
    $layout = storedLayout(['period', 'adjustments'], ReportKey::Arrivals);

    expect(resolvedKeys($layout, ['period', 'tax:SST', 'adjustments']))->toBe(['period', 'adjustments', 'tax:SST']);
});
