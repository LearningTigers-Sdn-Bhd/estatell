import { expect, test } from 'vite-plus/test';
import {
    addDays,
    canShiftDates,
    clampEdgeDate,
    clampShift,
    daysBetween,
    edgeHandles,
    resizedDates,
    shiftedDates,
} from './resize-target';

type Stay = App.Data.StayView.StayData;

/** A Confirmed stay of three nights: 16 to 18 Oct, departing 19 Oct. */
function stay(overrides: Partial<Stay> = {}): Stay {
    return {
        unitId: 1,
        reservationId: 1,
        roomTypeId: 7,
        reference: 'R-1',
        guestLabel: 'Ahmad',
        groupName: null,
        unitCount: 1,
        source: {} as Stay['source'],
        adults: 2,
        children: 0,
        status: { value: 'confirmed', label: 'Confirmed', tone: 'info' },
        balanceDue: false,
        isPackagePlan: false,
        arrivalDate: '2026-10-16',
        departureDate: '2026-10-19',
        arrivalTime: '15:00',
        departureTime: '11:00',
        segments: [],
        ...overrides,
    };
}

/** A Checked-in stay: arrived 13 Oct, departing 17 Oct. */
function checkedIn(overrides: Partial<Stay> = {}): Stay {
    return stay({
        status: { value: 'checked_in', label: 'Checked in', tone: 'success' },
        arrivalDate: '2026-10-13',
        departureDate: '2026-10-17',
        ...overrides,
    });
}

const dates = Array.from({ length: 14 }, (_, index) =>
    addDays('2026-10-14', index),
);
const window = {
    businessDate: '2026-10-14',
    firstDate: '2026-10-14',
    lastDate: '2026-10-27',
};
const whole = { start: 2, span: 3, openStart: false, openEnd: false };

test('addDays moves across month ends', () => {
    expect(addDays('2026-10-31', 1)).toBe('2026-11-01');
    expect(addDays('2026-11-01', -1)).toBe('2026-10-31');
});

test('daysBetween counts the nights of a stay', () => {
    expect(daysBetween('2026-10-30', '2026-11-02')).toBe(3);
    expect(daysBetween('2026-10-14', '2026-10-14')).toBe(0);
});

test('a Confirmed stay has both handles, a Checked-in stay only the right one', () => {
    expect(edgeHandles(stay(), whole, dates)).toEqual({
        left: true,
        right: true,
    });
    expect(
        edgeHandles(
            checkedIn({
                arrivalDate: '2026-10-16',
                departureDate: '2026-10-19',
            }),
            whole,
            dates,
        ),
    ).toEqual({ left: false, right: true });
});

test('no handle for other statuses or a Package plan', () => {
    const none = { left: false, right: false };

    for (const value of ['checked_out', 'cancelled', 'no_show']) {
        expect(
            edgeHandles(
                stay({ status: { value, label: '', tone: 'neutral' } }),
                whole,
                dates,
            ),
        ).toEqual(none);
    }

    expect(edgeHandles(stay({ isPackagePlan: true }), whole, dates)).toEqual(
        none,
    );
});

test('no handle on an edge cut by the window', () => {
    expect(edgeHandles(stay(), { ...whole, openEnd: true }, dates)).toEqual({
        left: true,
        right: false,
    });
    expect(
        edgeHandles(
            stay(),
            { start: 0, span: 5, openStart: true, openEnd: false },
            dates,
        ),
    ).toEqual({ left: false, right: true });
});

test('no handle at the boundary between two segments of one stay', () => {
    // The first segment holds 16 and 17 Oct; the last night is 18 Oct.
    expect(
        edgeHandles(
            stay(),
            { start: 2, span: 2, openStart: false, openEnd: false },
            dates,
        ),
    ).toEqual({ left: true, right: false });
    // The second segment holds 18 Oct only.
    expect(
        edgeHandles(
            stay(),
            { start: 4, span: 1, openStart: false, openEnd: false },
            dates,
        ),
    ).toEqual({ left: false, right: true });
});

test('the right edge of a Confirmed stay stays at least one night and past tonight', () => {
    expect(
        clampEdgeDate({
            stay: stay(),
            edge: 'right',
            date: '2026-10-10',
            ...window,
        }),
    ).toBe('2026-10-17');
    // A stay that arrived before the business date: business date + 1 is the floor.
    expect(
        clampEdgeDate({
            stay: stay({ arrivalDate: '2026-10-10' }),
            edge: 'right',
            date: '2026-10-12',
            ...window,
        }),
    ).toBe('2026-10-15');
});

test('the right edge of a Checked-in stay can reach the business date', () => {
    expect(
        clampEdgeDate({
            stay: checkedIn(),
            edge: 'right',
            date: '2026-10-01',
            ...window,
        }),
    ).toBe('2026-10-14');
    // Arrived today: at least arrival + 1.
    expect(
        clampEdgeDate({
            stay: checkedIn({ arrivalDate: '2026-10-14' }),
            edge: 'right',
            date: '2026-10-01',
            ...window,
        }),
    ).toBe('2026-10-15');
});

test('the right edge stops one day after the last loaded date', () => {
    expect(
        clampEdgeDate({
            stay: stay(),
            edge: 'right',
            date: '2026-12-01',
            ...window,
        }),
    ).toBe('2026-10-28');
});

test('the left edge stays between the business date and the night before the departure', () => {
    const base = { stay: stay(), edge: 'left' as const, ...window };

    expect(clampEdgeDate({ ...base, date: '2026-10-01' })).toBe('2026-10-14');
    expect(clampEdgeDate({ ...base, date: '2026-10-15' })).toBe('2026-10-15');
    expect(clampEdgeDate({ ...base, date: '2026-10-25' })).toBe('2026-10-18');
});

test('the left edge stops at the first loaded date when it is after the business date', () => {
    expect(
        clampEdgeDate({
            stay: stay({ arrivalDate: '2026-10-20' }),
            edge: 'left',
            date: '2026-10-01',
            businessDate: '2026-10-10',
            firstDate: '2026-10-14',
            lastDate: '2026-10-27',
        }),
    ).toBe('2026-10-14');
});

test('an edge keeps its date when no date fits', () => {
    expect(
        clampEdgeDate({
            stay: stay({
                arrivalDate: '2026-10-27',
                departureDate: '2026-10-28',
            }),
            edge: 'left',
            date: '2026-10-20',
            businessDate: '2026-10-28',
            firstDate: '2026-10-14',
            lastDate: '2026-10-27',
        }),
    ).toBe('2026-10-27');
});

test('resizedDates moves one edge and is null on the same date', () => {
    expect(resizedDates(stay(), 'right', '2026-10-21')).toEqual({
        arrivalDate: '2026-10-16',
        departureDate: '2026-10-21',
    });
    expect(resizedDates(stay(), 'left', '2026-10-15')).toEqual({
        arrivalDate: '2026-10-15',
        departureDate: '2026-10-19',
    });
    expect(resizedDates(stay(), 'right', '2026-10-19')).toBeNull();
    expect(resizedDates(stay(), 'left', '2026-10-16')).toBeNull();
});

test('only a confirmed stay that is fully inside the window can move sideways', () => {
    expect(canShiftDates(stay(), '2026-10-10', '2026-10-30')).toBe(true);
    expect(canShiftDates(checkedIn(), '2026-10-10', '2026-10-30')).toBe(false);
    expect(
        canShiftDates(
            stay({ isPackagePlan: true }),
            '2026-10-10',
            '2026-10-30',
        ),
    ).toBe(false);
    // Cut by the left edge, or running past the right edge.
    expect(canShiftDates(stay(), '2026-10-17', '2026-10-30')).toBe(false);
    expect(canShiftDates(stay(), '2026-10-10', '2026-10-17')).toBe(false);
    // Departing on the day after the last loaded date still fits.
    expect(canShiftDates(stay(), '2026-10-10', '2026-10-18')).toBe(true);
});

test('a shift keeps the arrival off the past and the departure inside the window', () => {
    const clamp = (columns: number, businessDate = '2026-10-14') =>
        clampShift({
            stay: stay(),
            columns,
            businessDate,
            firstDate: '2026-10-10',
            lastDate: '2026-10-30',
        });

    expect(clamp(2)).toBe(2);
    expect(clamp(-2)).toBe(-2);
    // Arrival 16 Oct, business date 14 Oct: two columns left at most.
    expect(clamp(-5)).toBe(-2);
    // Departure 19 Oct, last loaded date 30 Oct: 12 columns right at most.
    expect(clamp(40)).toBe(12);
    // Past the business date the first loaded date does not matter.
    expect(clamp(-5, '2026-10-12')).toBe(-4);
});

test('a stay that begins before the business date moves by enough to leave the past, or not at all', () => {
    const late = stay({
        arrivalDate: '2026-10-13',
        departureDate: '2026-10-16',
    });
    const clamp = (columns: number) =>
        clampShift({
            stay: late,
            columns,
            businessDate: '2026-10-15',
            firstDate: '2026-10-10',
            lastDate: '2026-10-30',
        });

    expect(clamp(-1)).toBe(0);
    expect(clamp(1)).toBe(0);
    expect(clamp(2)).toBe(2);
    expect(clamp(5)).toBe(5);
});

test('a shift gives the stay both new dates', () => {
    expect(shiftedDates(stay(), 2)).toEqual({
        arrivalDate: '2026-10-18',
        departureDate: '2026-10-21',
    });
    expect(shiftedDates(stay(), -3)).toEqual({
        arrivalDate: '2026-10-13',
        departureDate: '2026-10-16',
    });
});
