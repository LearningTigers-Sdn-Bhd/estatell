import { expect, test } from 'vite-plus/test';
import {
    adultCounts,
    buildCellPayload,
    cellFormProblem,
    cellFormValues,
    isCellFormDirty,
    priceChanged,
    restrictionsChanged,
} from './cell-form';

type Cell = App.Data.Rates.RateCalendarCellData;

const cell = (overrides: Partial<Cell> = {}): Cell => ({
    date: '2026-10-05',
    amount: '200.00',
    minStay: null,
    maxStay: null,
    closedToArrival: false,
    closedToDeparture: false,
    stopSell: false,
    occupancy: [],
    arrivalNotAllowed: false,
    hours: [],
    ...overrides,
});

const target = {
    ratePlanId: 10,
    roomTypeId: 1,
    date: '2026-10-05',
    isoWeekday: 1,
};

test('reads the values of a cell, and of a date with no cell', () => {
    expect(
        cellFormValues(
            cell({
                minStay: 2,
                stopSell: true,
                occupancy: [
                    { adults: 1, amount: '150.00' },
                    { adults: 2, amount: '260.00' },
                ],
            }),
            true,
        ),
    ).toEqual({
        amount: '200.00',
        occupancy: { '1': '150.00', '2': '260.00' },
        stopSell: true,
        closedToArrival: false,
        closedToDeparture: false,
        minStay: '2',
        maxStay: '',
    });
    expect(cellFormValues(undefined, true)).toMatchObject({
        amount: '',
        occupancy: {},
        minStay: '',
    });
});

test('a plan with no own price has no price to edit', () => {
    expect(
        cellFormValues(
            cell({
                amount: '180.00',
                occupancy: [{ adults: 1, amount: '1.00' }],
            }),
            false,
        ),
    ).toMatchObject({ amount: '', occupancy: {} });
});

test('lists the adult counts a room type can price', () => {
    expect(adultCounts(3)).toEqual([1, 2, 3]);
    expect(adultCounts(0)).toEqual([]);
});

test('the same price typed a different way is not a change', () => {
    const initial = cellFormValues(cell(), true);

    expect(priceChanged(initial, { ...initial, amount: '200' })).toBe(false);
    expect(priceChanged(initial, { ...initial, amount: '200.5' })).toBe(true);
    expect(
        priceChanged(initial, { ...initial, occupancy: { '2': '   ' } }),
    ).toBe(false);
    expect(
        priceChanged(initial, { ...initial, occupancy: { '2': '260' } }),
    ).toBe(true);
});

test('a restriction change is a change', () => {
    const initial = cellFormValues(cell(), true);

    expect(restrictionsChanged(initial, initial)).toBe(false);
    expect(restrictionsChanged(initial, { ...initial, stopSell: true })).toBe(
        true,
    );
    expect(restrictionsChanged(initial, { ...initial, minStay: '2' })).toBe(
        true,
    );
    expect(isCellFormDirty(initial, initial)).toBe(false);
    expect(
        isCellFormDirty(initial, { ...initial, closedToArrival: true }),
    ).toBe(true);
});

test('a price by adults with no price is a problem, and so is clearing a price', () => {
    const initial = cellFormValues(cell(), true);
    const empty = cellFormValues(undefined, true);

    expect(cellFormProblem(initial, { ...initial, amount: '' })).toContain(
        'stop sell',
    );
    expect(
        cellFormProblem(empty, { ...empty, occupancy: { '2': '260' } }),
    ).toContain('before you set prices by adults');
    expect(cellFormProblem(empty, { ...empty, stopSell: true })).toBeNull();
    expect(cellFormProblem(initial, { ...initial, amount: '210' })).toBeNull();
});

test('sends the price and prices by adults only when they changed', () => {
    const initial = cellFormValues(cell({ minStay: 3 }), true);

    const restrictionOnly = buildCellPayload(
        target,
        initial,
        { ...initial, stopSell: true, minStay: '' },
        true,
    );

    expect(restrictionOnly.room_types[0].days).toEqual([]);
    expect(restrictionOnly.restrictions).toEqual({
        stop_sell: true,
        closed_to_arrival: false,
        closed_to_departure: false,
        min_stay: null,
        max_stay: null,
    });

    const priced = buildCellPayload(
        target,
        initial,
        {
            ...initial,
            amount: ' 210 ',
            occupancy: { '2': '260', '3': '', '1': '150.5' },
        },
        true,
    );

    expect(priced.from).toBe('2026-10-05');
    expect(priced.to).toBe('2026-10-05');
    expect(priced.room_types[0]).toEqual({
        room_type_id: 1,
        days: [
            {
                weekday: 1,
                amount: '210',
                occupancy: [
                    { adults: 1, amount: '150.50' },
                    { adults: 2, amount: '260.00' },
                ],
            },
        ],
    });
});

test('never sends a price for a plan that has none of its own', () => {
    const initial = cellFormValues(cell(), false);

    expect(
        buildCellPayload(target, initial, { ...initial, amount: '99' }, false)
            .room_types[0].days,
    ).toEqual([]);
});

test('sends stay limits as numbers', () => {
    const initial = cellFormValues(undefined, true);

    expect(
        buildCellPayload(
            target,
            initial,
            { ...initial, minStay: '2', maxStay: ' 7 ' },
            true,
        ).restrictions,
    ).toMatchObject({ min_stay: 2, max_stay: 7 });
});
