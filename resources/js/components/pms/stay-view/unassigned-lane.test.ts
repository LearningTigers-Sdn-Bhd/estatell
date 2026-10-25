import { expect, test } from 'vite-plus/test';
import {
    isUnassignedRow,
    packUnassigned,
    unassignedLanes,
} from './unassigned-lane';

type Stay = App.Data.StayView.StayData;
type Room = App.Data.StayView.RoomRowData;

const segment = (id: string, firstNight: string, lastNight: string) => ({
    id,
    firstNight,
    lastNight,
});

test('overlapping bars take separate rows and a bar may begin on the day another ends', () => {
    const packed = packUnassigned([
        segment('a', '2026-10-14', '2026-10-16'),
        segment('b', '2026-10-15', '2026-10-15'),
        segment('c', '2026-10-17', '2026-10-18'),
        segment('d', '2026-10-16', '2026-10-17'),
    ]);

    // c begins the day after a's last night, so it shares a's row.
    expect(Object.fromEntries(packed)).toEqual({ a: 0, b: 1, c: 0, d: 1 });
});

test('bars that never overlap share one row, and no bars need no rows', () => {
    const packed = packUnassigned([
        segment('b', '2026-10-17', '2026-10-18'),
        segment('a', '2026-10-14', '2026-10-16'),
    ]);

    expect([...packed.values()]).toEqual([0, 0]);
    expect(packUnassigned([]).size).toBe(0);
});

test('uses as many rows as the busiest night', () => {
    const packed = packUnassigned([
        segment('a', '2026-10-14', '2026-10-20'),
        segment('b', '2026-10-14', '2026-10-15'),
        segment('c', '2026-10-14', '2026-10-17'),
        segment('d', '2026-10-16', '2026-10-18'),
    ]);

    expect(Math.max(...packed.values()) + 1).toBe(3);
});

const stay = (unitId: number, rowKey: string, first: string, last: string) =>
    ({
        unitId,
        arrivalDate: first,
        departureDate: '2026-10-30',
        segments: [{ rowKey, firstNight: first, lastNight: last }],
    }) as Stay;

const rooms = [
    { key: 'room-1', roomTypeId: 20, roomTypeName: 'Suite' },
    { key: 'room-2', roomTypeId: 10, roomTypeName: 'Deluxe' },
] as Room[];

test('lanes follow room type order, with sub-row keys and the units in each', () => {
    const { lanes, subRowOf } = unassignedLanes(
        [
            stay(1, 'unassigned-10', '2026-10-14', '2026-10-16'),
            stay(2, 'unassigned-10', '2026-10-15', '2026-10-15'),
            stay(3, 'unassigned-20', '2026-10-14', '2026-10-14'),
            stay(4, 'room-1', '2026-10-14', '2026-10-14'),
            stay(5, 'unassigned-30', '2026-10-14', '2026-10-14'),
        ],
        rooms,
    );

    expect(lanes).toEqual([
        {
            roomTypeId: 20,
            roomTypeName: 'Suite',
            rowKeys: ['unassigned-20-0'],
            units: 1,
        },
        {
            roomTypeId: 10,
            roomTypeName: 'Deluxe',
            rowKeys: ['unassigned-10-0', 'unassigned-10-1'],
            units: 2,
        },
        {
            roomTypeId: 30,
            roomTypeName: 'Unknown room type',
            rowKeys: ['unassigned-30-0'],
            units: 1,
        },
    ]);
    expect(subRowOf.get('stay-1-0')).toBe('unassigned-10-0');
    expect(subRowOf.get('stay-2-0')).toBe('unassigned-10-1');
    expect(subRowOf.has('stay-4-0')).toBe(false);
    expect(unassignedLanes([], rooms).lanes).toEqual([]);
});

test('knows an unassigned row key', () => {
    expect(isUnassignedRow('unassigned-7')).toBe(true);
    expect(isUnassignedRow('unassigned-7-1')).toBe(true);
    expect(isUnassignedRow('room-7')).toBe(false);
});
