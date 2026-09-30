import { expect, test } from 'vite-plus/test';
import {
    buildRows,
    cellFlags,
    formatAmount,
    monthLabel,
    planOptions,
    shiftMonth,
    stayLimit,
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

test('moves and labels months', () => {
    expect(shiftMonth('2026-12', 1)).toBe('2027-01');
    expect(shiftMonth('2026-01', -1)).toBe('2025-12');
    expect(monthLabel('2026-10')).toBe('October 2026');
});
