import { expect, test } from 'vite-plus/test';
import { buildDateColumns } from './date-columns';

test('builds a column for each date with weekday, month and today marker', () => {
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
    expect(columns[0].month).toBe('Oct');
});

test('names the month of each column across a month end', () => {
    const columns = buildDateColumns(
        ['2026-09-30', '2026-10-01'],
        '2026-09-30',
    );

    expect(columns.map((column) => `${column.day} ${column.month}`)).toEqual([
        '30 Sep',
        '1 Oct',
    ]);
});

test('marks the weekend nights the property sets', () => {
    const dates = ['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04'];

    expect(
        buildDateColumns(dates, '2026-10-01', [5, 6]).map(
            (column) => column.isWeekend,
        ),
    ).toEqual([false, true, true, false]);
    expect(
        buildDateColumns(dates, '2026-10-01', [6, 7]).map(
            (column) => column.isWeekend,
        ),
    ).toEqual([false, false, true, true]);
    expect(
        buildDateColumns(dates, '2026-10-01').some(
            (column) => column.isWeekend,
        ),
    ).toBe(false);
});
