import { expect, test } from 'vite-plus/test';
import {
    columnAt,
    dropAt,
    movableNights,
    rowOnNight,
    rowTakesDrop,
} from './drop-target';

type Stay = App.Data.StayView.StayData;
type Target = App.Data.Reservations.MoveTargetData;

/** A stay of three nights, 14 to 16 Oct, in room 1 of room type 7. */
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
        arrivalDate: '2026-10-14',
        departureDate: '2026-10-17',
        arrivalTime: '15:00',
        departureTime: '11:00',
        segments: [
            {
                rowKey: 'room-1',
                firstNight: '2026-10-14',
                lastNight: '2026-10-16',
            },
        ],
        ...overrides,
    };
}

function targets(list: Target[]): Map<string, Target> {
    return new Map(list.map((target) => [target.rowKey, target]));
}

const free = targets([
    { rowKey: 'room-1', roomTypeId: 7, freeFrom: '2026-10-14' },
    { rowKey: 'room-2', roomTypeId: 7, freeFrom: '2026-10-14' },
    { rowKey: 'room-3', roomTypeId: 7, freeFrom: '2026-10-15' },
    { rowKey: 'room-9', roomTypeId: 8, freeFrom: '2026-10-14' },
]);

test('a confirmed stay moves from its arrival, a guest in house from the business date', () => {
    expect(movableNights(stay(), '2026-10-12')).toEqual({
        first: '2026-10-14',
        last: '2026-10-16',
    });
    expect(
        movableNights(
            stay({
                status: {
                    value: 'checked_in',
                    label: 'Checked in',
                    tone: 'success',
                },
            }),
            '2026-10-15',
        ),
    ).toEqual({ first: '2026-10-15', last: '2026-10-16' });
});

test('a stay that is checked out or whose last night is past cannot move', () => {
    const checkedOut = stay({
        status: { value: 'checked_out', label: 'Checked out', tone: 'neutral' },
    });

    expect(movableNights(checkedOut, '2026-10-12')).toBeNull();
    expect(movableNights(stay(), '2026-10-17')).toBeNull();
});

test('the column under the pointer stays inside the dates', () => {
    expect(columnAt(300 + 88 * 2 + 10, 12, 288, 88, 14)).toBe(2);
    expect(columnAt(100, 12, 288, 88, 14)).toBe(0);
    expect(columnAt(99_999, 12, 288, 88, 14)).toBe(13);
});

test('the row on a night comes from the segments', () => {
    const moved = stay({
        segments: [
            {
                rowKey: 'room-1',
                firstNight: '2026-10-14',
                lastNight: '2026-10-14',
            },
            {
                rowKey: 'room-2',
                firstNight: '2026-10-15',
                lastNight: '2026-10-16',
            },
        ],
    });

    expect(rowOnNight(moved, '2026-10-15')).toBe('room-2');
    expect(rowOnNight(moved, '2026-10-17')).toBeNull();
});

test('a drop moves from the night under the pointer', () => {
    const nights = { first: '2026-10-14', last: '2026-10-16' };

    expect(
        dropAt({
            stay: stay(),
            nights,
            rowKey: 'room-2',
            date: '2026-10-15',
            targets: free,
        }),
    ).toEqual({
        rowKey: 'room-2',
        fromNight: '2026-10-15',
        isRoomTypeChange: false,
    });
});

test('a drop on a past night moves from tonight, and one after the stay from the last night', () => {
    const nights = { first: '2026-10-15', last: '2026-10-16' };

    expect(
        dropAt({
            stay: stay(),
            nights,
            rowKey: 'room-2',
            date: '2026-10-10',
            targets: free,
        })?.fromNight,
    ).toBe('2026-10-15');
    expect(
        dropAt({
            stay: stay(),
            nights,
            rowKey: 'room-2',
            date: '2026-10-20',
            targets: free,
        })?.fromNight,
    ).toBe('2026-10-16');
});

test('a row taken tonight takes a drop from the night it is free', () => {
    const nights = { first: '2026-10-14', last: '2026-10-16' };
    const drop = (date: string) =>
        dropAt({ stay: stay(), nights, rowKey: 'room-3', date, targets: free });

    expect(drop('2026-10-14')).toBeNull();
    expect(drop('2026-10-15')?.fromNight).toBe('2026-10-15');
});

test('another room type moves the whole stay, and only when the arrival can move', () => {
    expect(
        dropAt({
            stay: stay(),
            nights: { first: '2026-10-14', last: '2026-10-16' },
            rowKey: 'room-9',
            date: '2026-10-16',
            targets: free,
        }),
    ).toEqual({
        rowKey: 'room-9',
        fromNight: '2026-10-14',
        isRoomTypeChange: true,
    });
    expect(
        dropAt({
            stay: stay(),
            nights: { first: '2026-10-15', last: '2026-10-16' },
            rowKey: 'room-9',
            date: '2026-10-16',
            targets: free,
        }),
    ).toBeNull();
});

test('a row that is not a target, or the row the unit is already in, takes no drop', () => {
    const nights = { first: '2026-10-14', last: '2026-10-16' };

    expect(
        dropAt({
            stay: stay(),
            nights,
            rowKey: 'room-5',
            date: '2026-10-14',
            targets: free,
        }),
    ).toBeNull();
    expect(
        dropAt({
            stay: stay(),
            nights,
            rowKey: 'room-1',
            date: '2026-10-15',
            targets: free,
        }),
    ).toBeNull();
    expect(rowTakesDrop(stay(), nights, 'room-1', free)).toBe(false);
    expect(rowTakesDrop(stay(), nights, 'room-3', free)).toBe(true);
});
