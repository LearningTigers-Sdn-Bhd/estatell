import { expect, test } from 'vitest';
import { autoFillDayUse, buildRows, toPayload } from './pricing-state';
import type { RoomTypeGrid } from './pricing-state';

const roomType = {
    id: 1,
    name: 'Deluxe',
    baseOccupancy: 2,
    maxAdults: 3,
    pricedUntil: null,
    ending: false,
};

const hourRows = (min: number, max: number) =>
    buildRows('day_use', roomType, false, [], { min, max });

const first = (rows: ReturnType<typeof hourRows>, hours: number) =>
    rows.find((row) => row.hours === hours)?.values[0];

test('a day use plan gets one row per hour count from its minimum to its maximum', () => {
    const rows = hourRows(2, 5);

    expect(rows.map((row) => row.label)).toEqual([
        '2 hours',
        '3 hours',
        '4 hours',
        '5 hours',
    ]);
    expect(rows.every((row) => row.kind === 'hours')).toBe(true);
});

test('Auto steps up by an amount and stops at the cap', () => {
    const rows = autoFillDayUse(hourRows(1, 12), {
        firstHours: 1,
        firstPrice: 40,
        direction: 'more',
        step: 20,
        unit: 'amount',
        cap: 180,
    });

    expect(first(rows, 1)).toBe('40');
    expect(first(rows, 2)).toBe('60');
    expect(first(rows, 3)).toBe('80');
    expect(first(rows, 8)).toBe('180');
    expect(first(rows, 12)).toBe('180');
    expect(
        rows.every((row) => row.values.every((v) => v === row.values[0])),
    ).toBe(true);
});

test('Auto percent steps compound and hours below the first block stay empty', () => {
    const rows = autoFillDayUse(hourRows(1, 4), {
        firstHours: 2,
        firstPrice: 100,
        direction: 'more',
        step: 10,
        unit: 'percent',
        cap: null,
    });

    expect(first(rows, 1)).toBe('');
    expect(first(rows, 2)).toBe('100');
    expect(first(rows, 3)).toBe('110');
    expect(first(rows, 4)).toBe('121');
});

test('Auto can take money off for each extra hour and never goes below zero', () => {
    const rows = autoFillDayUse(hourRows(1, 4), {
        firstHours: 1,
        firstPrice: 50,
        direction: 'less',
        step: 30,
        unit: 'amount',
        cap: null,
    });

    expect(first(rows, 2)).toBe('20');
    expect(first(rows, 3)).toBe('0');
});

test('the payload sends only the hours that have a price', () => {
    const rows = hourRows(2, 3).map((row) =>
        row.hours === 2
            ? { ...row, values: ['60', '', '', '', '', '', '70'] }
            : row,
    );
    const grid: RoomTypeGrid = { roomTypeId: 1, byAdults: false, rows };
    const days = toPayload([grid])[0].days;

    expect(days[0]).toEqual({
        weekday: 1,
        hours: [{ hours: 2, amount: '60' }],
    });
    expect(days[1]).toEqual({ weekday: 2, hours: [] });
    expect(days[6]).toEqual({
        weekday: 7,
        hours: [{ hours: 2, amount: '70' }],
    });
});
