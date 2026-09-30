import { expect, test } from 'vite-plus/test';
import {
    buildRows,
    cellFlags,
    formatAmount,
    activePreset,
    addDays,
    presetRange,
    rangeContains,
    rangeLength,
    shiftRange,
    todayRange,
    planOptions,
    stayLimit,
    toggleCollapsed,
} from './calendar-model';

type RoomType = App.Data.Rates.RateCalendarRoomTypeData;
type Cell = App.Data.Rates.RateCalendarCellData;

const cell = (overrides: Partial<Cell> = {}): Cell => ({
    date: '2026-10-05',
    amount: '180.00',
    minStay: null,
    maxStay: null,
    closedToArrival: false,
    closedToDeparture: false,
    stopSell: false,
    ...overrides,
});

const plan = (id: number, name: string) => ({
    id,
    name,
    pricingBasis: 'per_room' as const,
    derived: false,
    followsName: null,
    cells: [],
});

const roomTypes: RoomType[] = [
    {
        id: 1,
        name: 'Deluxe',
        plans: [plan(10, 'Basic'), plan(11, 'Breakfast')],
    },
    { id: 2, name: 'Suite', plans: [plan(10, 'Basic')] },
];

test('builds a heading and a row for each plan', () => {
    const rows = buildRows(roomTypes, { roomTypeId: '', ratePlanId: '' });

    expect(rows.map((row) => row.key)).toEqual([
        'group-1',
        'row-1-10',
        'row-1-11',
        'group-2',
        'row-2-10',
    ]);
});

test('leaves out room types and plans the filters exclude', () => {
    expect(
        buildRows(roomTypes, { roomTypeId: '2', ratePlanId: '' }).map(
            (row) => row.key,
        ),
    ).toEqual(['group-2', 'row-2-10']);
    expect(
        buildRows(roomTypes, { roomTypeId: '', ratePlanId: '11' }).map(
            (row) => row.key,
        ),
    ).toEqual(['group-1', 'row-1-11']);
    expect(buildRows(roomTypes, { roomTypeId: '2', ratePlanId: '11' })).toEqual(
        [],
    );
});

test('lists each plan once for the plan filter', () => {
    expect(planOptions(roomTypes)).toEqual([
        { value: '10', label: 'Basic' },
        { value: '11', label: 'Breakfast' },
    ]);
});

test('lists restriction flags in a fixed order', () => {
    expect(cellFlags(cell())).toEqual([]);
    expect(
        cellFlags(cell({ closedToDeparture: true, stopSell: true })),
    ).toEqual(['stop-sell', 'closed-departure']);
});

test('describes stay limits', () => {
    expect(stayLimit(cell())).toBeNull();
    expect(stayLimit(cell({ minStay: 2 }))).toBe('Min 2');
    expect(stayLimit(cell({ maxStay: 7 }))).toBe('Max 7');
    expect(stayLimit(cell({ minStay: 2, maxStay: 5 }))).toBe('2-5 nights');
});

test('formats prices without trailing zeros', () => {
    expect(formatAmount('180.00')).toBe('180');
    expect(formatAmount('180.50')).toBe('180.5');
    expect(formatAmount('1234.00')).toBe('1,234');
    expect(formatAmount(null)).toBe('');
});

test('adds days across month and year ends', () => {
    expect(addDays('2026-10-31', 1)).toBe('2026-11-01');
    expect(addDays('2026-12-31', 1)).toBe('2027-01-01');
    expect(addDays('2026-03-01', -1)).toBe('2026-02-28');
    expect(addDays('2028-03-01', -1)).toBe('2028-02-29');
});

test('counts the dates in a range', () => {
    expect(rangeLength({ from: '2026-10-05', to: '2026-10-05' })).toBe(1);
    expect(rangeLength({ from: '2026-10-05', to: '2026-10-18' })).toBe(14);
    expect(rangeLength({ from: '2026-10-01', to: '2026-10-31' })).toBe(31);
});

test('builds preset ranges', () => {
    expect(presetRange(14, '2026-10-05')).toEqual({
        from: '2026-10-05',
        to: '2026-10-18',
    });
    expect(presetRange(21, '2026-10-05')).toEqual({
        from: '2026-10-05',
        to: '2026-10-25',
    });
    expect(presetRange('month', '2026-10-15')).toEqual({
        from: '2026-10-01',
        to: '2026-10-31',
    });
    expect(presetRange('month', '2028-02-10')).toEqual({
        from: '2028-02-01',
        to: '2028-02-29',
    });
});

test('finds the active preset', () => {
    expect(activePreset({ from: '2026-10-05', to: '2026-10-18' })).toBe(14);
    expect(activePreset({ from: '2026-10-05', to: '2026-10-25' })).toBe(21);
    expect(activePreset({ from: '2026-10-01', to: '2026-10-31' })).toBe(
        'month',
    );
    expect(activePreset({ from: '2026-10-05', to: '2026-10-09' })).toBeNull();
});

test('steps a range by its length, or by a month for a month range', () => {
    expect(shiftRange({ from: '2026-10-05', to: '2026-10-18' }, 1)).toEqual({
        from: '2026-10-19',
        to: '2026-11-01',
    });
    expect(shiftRange({ from: '2026-10-05', to: '2026-10-18' }, -1)).toEqual({
        from: '2026-09-21',
        to: '2026-10-04',
    });
    expect(shiftRange({ from: '2026-10-01', to: '2026-10-31' }, 1)).toEqual({
        from: '2026-11-01',
        to: '2026-11-30',
    });
    expect(shiftRange({ from: '2026-01-01', to: '2026-01-31' }, -1)).toEqual({
        from: '2025-12-01',
        to: '2025-12-31',
    });
});

test('starts the range today, keeping its length', () => {
    expect(
        todayRange('2026-10-20', { from: '2026-10-05', to: '2026-10-25' }),
    ).toEqual({ from: '2026-10-20', to: '2026-11-09' });
    expect(
        todayRange('2026-10-20', { from: '2026-09-01', to: '2026-09-30' }),
    ).toEqual({ from: '2026-10-01', to: '2026-10-31' });
    expect(
        todayRange('2026-10-20', { from: '2026-08-01', to: '2026-10-31' }),
    ).toEqual({ from: '2026-10-20', to: '2026-12-20' });
});

test('checks whether a date is in a range', () => {
    const range = { from: '2026-10-05', to: '2026-10-18' };

    expect(rangeContains(range, '2026-10-05')).toBe(true);
    expect(rangeContains(range, '2026-10-18')).toBe(true);
    expect(rangeContains(range, '2026-10-19')).toBe(false);
});

test('a collapsed room type keeps its heading and drops its plan rows', () => {
    const rows = buildRows(
        roomTypes,
        { roomTypeId: '', ratePlanId: '' },
        new Set([1]),
    );

    expect(rows.map((row) => row.key)).toEqual([
        'group-1',
        'group-2',
        'row-2-10',
    ]);
    expect(rows[0]).toMatchObject({
        kind: 'group',
        collapsed: true,
        planCount: 2,
    });
    expect(rows[1]).toMatchObject({
        kind: 'group',
        collapsed: false,
        planCount: 1,
    });
});

test('the plan count of a heading follows the filters', () => {
    const rows = buildRows(
        roomTypes,
        { roomTypeId: '', ratePlanId: '11' },
        new Set([1]),
    );

    expect(rows).toHaveLength(1);
    expect(rows[0]).toMatchObject({ collapsed: true, planCount: 1 });
});

test('toggling collapses a room type and expands it again without changing the old set', () => {
    const none = new Set<number>();
    const collapsed = toggleCollapsed(none, 1);

    expect([...collapsed]).toEqual([1]);
    expect([...toggleCollapsed(collapsed, 1)]).toEqual([]);
    expect([...toggleCollapsed(collapsed, 2)].sort((a, b) => a - b)).toEqual([
        1, 2,
    ]);
    expect(none.size).toBe(0);
});
