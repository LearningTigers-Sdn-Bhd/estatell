import { expect, test } from 'vite-plus/test';
import {
    applySetAll,
    buildPairGrids,
    bulkColumns,
    columnValue,
    fitsColumns,
    monthsEnd,
    nextPlanSelection,
    pairKey,
    remapColumns,
    setColumnValue,
    toBulkPayload,
} from './bulk-price-model';
import type { SavedPrices } from './bulk-price-model';

type Plan = App.Data.Rates.RateBulkPlanData;

const roomType = (id: number, maxAdults = 2) => ({
    id,
    name: `Room ${id}`,
    baseOccupancy: 2,
    maxAdults,
});

const plan = (id: number, overrides: Partial<Plan> = {}): Plan => ({
    id,
    name: `Plan ${id}`,
    pricingBasis: 'per_room',
    derived: false,
    followsName: null,
    hasOwnPrices: true,
    roomTypes: [roomType(1), roomType(2)],
    ...overrides,
});

const FRI_SAT = [5, 6];

test('has the columns of each view', () => {
    expect(bulkColumns('every-day', FRI_SAT).map((c) => c.label)).toEqual([
        'Every day',
    ]);
    expect(bulkColumns('simple', FRI_SAT)).toEqual([
        { id: 'weekday', label: 'Weekdays', indexes: [0, 1, 2, 3, 6] },
        { id: 'weekend', label: 'Weekend', indexes: [4, 5] },
    ]);
    expect(bulkColumns('by-day', FRI_SAT).map((c) => c.label)).toEqual([
        'Mon',
        'Tue',
        'Wed',
        'Thu',
        'Fri',
        'Sat',
        'Sun',
    ]);
});

test('builds one grid for each plan and room type the plan sells, and keeps typed values', () => {
    const plans = [plan(10), plan(11, { roomTypes: [roomType(1)] })];
    const grids = buildPairGrids(plans, []);

    expect(grids.map((g) => pairKey(g.ratePlanId, g.roomTypeId))).toEqual([
        '10|1',
        '10|2',
        '11|1',
    ]);

    const typed = grids.map((grid, index) =>
        index === 0
            ? {
                  ...grid,
                  rows: grid.rows.map((row) =>
                      setColumnValue(
                          row,
                          bulkColumns('every-day', FRI_SAT)[0],
                          '150',
                      ),
                  ),
              }
            : grid,
    );
    const rebuilt = buildPairGrids(plans, [2], typed);

    expect(rebuilt).toHaveLength(2);
    expect(rebuilt[0].rows[0].values).toEqual(Array(7).fill('150'));
});

test('a plan with no price of its own has no price rows but keeps its room types', () => {
    const [grid] = buildPairGrids(
        [plan(10, { derived: true, hasOwnPrices: false })],
        [],
    );

    expect(grid.priceable).toBe(false);
    expect(grid.rows).toEqual([]);
});

test('a view fits only when each column has one value, and values move between views', () => {
    const [grid] = buildPairGrids([plan(10)], []);
    const weekdayWeekend = bulkColumns('simple', FRI_SAT);
    const everyDay = bulkColumns('every-day', FRI_SAT);
    const typed = {
        ...grid,
        rows: grid.rows.map((row) =>
            setColumnValue(
                setColumnValue(row, weekdayWeekend[0], '150'),
                weekdayWeekend[1],
                '180',
            ),
        ),
    };

    expect(fitsColumns([typed], weekdayWeekend)).toBe(true);
    expect(fitsColumns([typed], everyDay)).toBe(false);
    expect(fitsColumns([typed], bulkColumns('by-day', FRI_SAT))).toBe(true);

    const [moved] = remapColumns(
        [typed],
        bulkColumns('simple', FRI_SAT),
        bulkColumns('simple', [6, 7]),
    );

    expect(columnValue(moved.rows[0], bulkColumns('simple', [6, 7])[1])).toBe(
        '180',
    );
});

const saved: SavedPrices = {
    '10|1': { '1': '100.00', '2': '100.00', '5': '120.00', '6': '120.00' },
};

test('Set all sets a price on every row that can take one', () => {
    const grids = buildPairGrids(
        [plan(10), plan(11, { derived: true, hasOwnPrices: false })],
        [],
    );
    const columns = bulkColumns('simple', FRI_SAT);
    const next = applySetAll(grids, columns, 'price', '99', {});

    expect(next[0].rows[0].values).toEqual(Array(7).fill('99'));
    expect(next.at(-1)!.rows).toEqual([]);
});

test('Set all moves the saved price of each column by a percent or an amount', () => {
    const [grid] = buildPairGrids([plan(10, { roomTypes: [roomType(1)] })], []);
    const columns = bulkColumns('simple', FRI_SAT);

    const percent = applySetAll([grid], columns, 'percent', '10', saved)[0];

    expect(columnValue(percent.rows[0], columns[0])).toBe('110');
    expect(columnValue(percent.rows[0], columns[1])).toBe('132');

    const amount = applySetAll([grid], columns, 'amount', '-5', saved)[0];

    expect(columnValue(amount.rows[0], columns[0])).toBe('95');
    expect(columnValue(amount.rows[0], columns[1])).toBe('115');
});

test('Set all leaves a column with no saved price, and ignores a blank or bad number', () => {
    const [grid] = buildPairGrids([plan(10, { roomTypes: [roomType(1)] })], []);
    const byDay = bulkColumns('by-day', FRI_SAT);
    const next = applySetAll([grid], byDay, 'percent', '10', saved)[0];

    expect(next.rows[0].values).toEqual([
        '110',
        '110',
        '',
        '',
        '132',
        '132',
        '',
    ]);
    expect(applySetAll([grid], byDay, 'price', '', saved)).toEqual([grid]);
    expect(applySetAll([grid], byDay, 'price', 'abc', saved)).toEqual([grid]);
});

test('builds the payload with one entry for each plan', () => {
    const grids = buildPairGrids(
        [plan(10), plan(11, { derived: true, hasOwnPrices: false })],
        [],
    );
    const priced = applySetAll(
        grids,
        bulkColumns('every-day', FRI_SAT),
        'price',
        '99',
        {},
    );
    const payload = toBulkPayload(priced);

    expect(payload.map((entry) => entry.rate_plan_id)).toEqual([10, 11]);
    expect(payload[0].room_types).toHaveLength(2);
    expect(payload[0].room_types[0].days[0]).toMatchObject({
        weekday: 1,
        amount: '99',
    });
    expect(payload[1].room_types[0].days[0]).toMatchObject({
        weekday: 1,
        amount: '',
    });
});

test('a Day use plan is picked on its own', () => {
    const plans = [plan(10), plan(11), plan(12, { pricingBasis: 'day_use' })];

    expect(nextPlanSelection(plans, ['10'], ['10', '11'])).toEqual([
        '10',
        '11',
    ]);
    expect(nextPlanSelection(plans, ['10', '11'], ['10', '11', '12'])).toEqual([
        '12',
    ]);
    expect(nextPlanSelection(plans, ['12'], ['12', '10'])).toEqual(['10']);
});

test('a range of whole months ends the day before the same date', () => {
    expect(monthsEnd('2026-10-01', 12)).toBe('2027-09-30');
    expect(monthsEnd('2026-10-21', 6)).toBe('2027-04-20');
    expect(monthsEnd('2026-10-21', 1)).toBe('2026-11-20');
    expect(monthsEnd('2026-12-15', 3)).toBe('2027-03-14');
});
