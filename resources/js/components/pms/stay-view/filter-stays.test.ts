import { expect, test } from 'vite-plus/test';
import { filterStays } from './filter-stays';

const room = (key: string, over: Partial<App.Data.StayView.RoomRowData> = {}) =>
    ({
        key,
        label: key,
        roomNumber: key,
        roomGroupId: 1,
        roomTypeId: 10,
        housekeepingStatus: 'clean',
        ...over,
    }) as App.Data.StayView.RoomRowData;

const stay = (
    unitId: number,
    rowKey: string,
    over: Partial<App.Data.StayView.StayData> = {},
) =>
    ({
        unitId,
        guestLabel: 'Guest',
        reference: `R-${unitId}`,
        groupName: null,
        segments: [
            { rowKey, firstNight: '2026-10-14', lastNight: '2026-10-15' },
        ],
        ...over,
    }) as App.Data.StayView.StayData;

const none = { roomGroupIds: [], roomTypeIds: [], statuses: [], search: '' };

const rooms = [
    room('room-1'),
    room('room-2', {
        roomGroupId: 2,
        roomTypeId: 20,
        housekeepingStatus: 'dirty',
    }),
    room('room-3', { roomGroupId: null }),
];
const stays = [stay(1, 'room-1'), stay(2, 'room-2'), stay(3, 'room-3')];

test('no filter keeps everything', () => {
    const result = filterStays(rooms, stays, none);

    expect(result.rooms).toHaveLength(3);
    expect(result.stays).toHaveLength(3);
});

test('filters by room group, room type and housekeeping status', () => {
    expect(
        filterStays(rooms, stays, { ...none, roomGroupIds: [2] }).rooms.map(
            (r) => r.key,
        ),
    ).toEqual(['room-2']);
    expect(
        filterStays(rooms, stays, { ...none, roomTypeIds: [10] }).rooms.map(
            (r) => r.key,
        ),
    ).toEqual(['room-1', 'room-3']);
    expect(
        filterStays(rooms, stays, { ...none, statuses: ['dirty'] }).stays.map(
            (s) => s.unitId,
        ),
    ).toEqual([2]);
});

test('a room with no group never matches a group filter', () => {
    expect(
        filterStays(rooms, stays, { ...none, roomGroupIds: [1, 2] }).rooms.map(
            (r) => r.key,
        ),
    ).toEqual(['room-1', 'room-2']);
});

test('search matches a room, a guest, a reference or a group name', () => {
    const guests = [
        stay(1, 'room-1', { guestLabel: 'Ahmad Faris' }),
        stay(2, 'room-2', { groupName: 'ABC Corp' }),
        stay(3, 'room-3'),
    ];

    expect(
        filterStays(rooms, guests, { ...none, search: 'ahmad' }).rooms.map(
            (r) => r.key,
        ),
    ).toEqual(['room-1']);
    expect(
        filterStays(rooms, guests, { ...none, search: 'abc' }).stays.map(
            (s) => s.unitId,
        ),
    ).toEqual([2]);
    expect(
        filterStays(rooms, guests, { ...none, search: 'R-3' }).rooms.map(
            (r) => r.key,
        ),
    ).toEqual(['room-3']);
    expect(
        filterStays(rooms, guests, { ...none, search: 'room-2' }).rooms.map(
            (r) => r.key,
        ),
    ).toEqual(['room-2']);
});

test('search and filters work together', () => {
    const result = filterStays(rooms, stays, {
        ...none,
        roomTypeIds: [20],
        search: 'R-1',
    });

    expect(result.rooms).toEqual([]);
    expect(result.stays).toEqual([]);
});
