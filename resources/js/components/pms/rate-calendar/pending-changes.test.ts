import { expect, test } from 'vite-plus/test';
import { cellFormValues } from './cell-form';
import {
    applyPending,
    buildPendingPayload,
    pendingErrors,
    pendingKey,
    stagePending,
} from './pending-changes';
import type { PendingChange } from './pending-changes';

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

const change = (
    overrides: Partial<PendingChange['current']> = {},
    date = '2026-10-05',
    ratePlanId = 10,
): PendingChange => {
    const initial = cellFormValues(cell({ date }), true);

    return {
        target: { ratePlanId, roomTypeId: 1, date, isoWeekday: 1 },
        roomTypeName: 'Twin',
        planName: 'Basic Rate',
        initial,
        current: { ...initial, ...overrides },
        ownPrice: true,
        dayUse: false,
    };
};

test('stages an edit and keeps one entry for a plan, room type and date', () => {
    const first = stagePending(new Map(), change({ amount: '210' }));
    const again = stagePending(first, change({ amount: '220' }));

    expect(again.size).toBe(1);
    expect(again.get(pendingKey(change().target))?.current.amount).toBe('220');
});

test('an edit back at the saved values is removed', () => {
    const staged = stagePending(new Map(), change({ amount: '210' }));

    expect(stagePending(staged, change({ amount: '200' })).size).toBe(0);
});

test('the staged edits of several dates and plans stay apart', () => {
    let pending = stagePending(new Map(), change({ amount: '210' }));

    pending = stagePending(pending, change({ amount: '190' }, '2026-10-06'));
    pending = stagePending(
        pending,
        change({ stopSell: true }, '2026-10-05', 11),
    );

    expect(pending.size).toBe(3);
});

test('shows the staged values on the cell and keeps the rest', () => {
    const shown = applyPending(
        cell({ minStay: 2, hours: [{ hours: 4, amount: '40.00' }] }),
        change({ amount: '210', stopSell: true, minStay: '' }),
    );

    expect(shown).toMatchObject({
        amount: '210.00',
        stopSell: true,
        minStay: null,
        hours: [{ hours: 4, amount: '40.00' }],
    });
    expect(applyPending(cell(), undefined)).toEqual(cell());
});

test('a date with no cell gets one when it is staged', () => {
    const shown = applyPending(
        undefined,
        change({ amount: '99', stopSell: true }),
    );

    expect(shown).toMatchObject({
        date: '2026-10-05',
        amount: '99.00',
        stopSell: true,
    });
});

test('builds one payload with the changes in the order they were staged', () => {
    let pending = stagePending(new Map(), change({ amount: '210' }));

    pending = stagePending(pending, change({ stopSell: true }, '2026-10-06'));

    const { changes } = buildPendingPayload(pending);

    expect(changes).toHaveLength(2);
    expect(changes[0]).toMatchObject({
        rate_plan_id: 10,
        room_type_id: 1,
        date: '2026-10-05',
        amount: '210',
    });
    expect(changes[1]).toMatchObject({ date: '2026-10-06' });
    expect(changes[1].amount).toBeUndefined();
    expect(changes[1].restrictions.stop_sell).toBe(true);
});

test('names the change an error belongs to', () => {
    let pending = stagePending(new Map(), change({ amount: '210' }));

    pending = stagePending(pending, change({ stopSell: true }, '2026-10-06'));

    expect(
        pendingErrors(
            { 'changes.1.restrictions.max_stay': 'Too low.', changes: 'Bad.' },
            pending,
        ),
    ).toEqual([
        { label: 'Twin · Basic Rate · 2026-10-06', message: 'Too low.' },
        { label: null, message: 'Bad.' },
    ]);
});
