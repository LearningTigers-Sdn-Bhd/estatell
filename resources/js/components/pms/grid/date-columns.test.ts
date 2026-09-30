import { expect, test } from 'vite-plus/test';
import { buildDateColumns } from './date-columns';

test('builds a column for each date with weekday and today marker', () => {
    const columns = buildDateColumns(
        ['2026-10-04', '2026-10-05', '2026-10-06'],
        '2026-10-05',
    );

    expect(columns.map((column) => column.weekday)).toEqual([
        'Sun',
        'Mon',
        'Tue',
    ]);
    expect(columns.map((column) => column.isoWeekday)).toEqual([7, 1, 2]);
    expect(columns.map((column) => column.isToday)).toEqual([
        false,
        true,
        false,
    ]);
    expect(columns[0].day).toBe(4);
});
