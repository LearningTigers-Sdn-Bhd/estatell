import { expect, test } from 'vite-plus/test';
import { buildRows, toggleCollapsed } from './group-rows';

const room = (
    key: string,
    roomNumber: string,
    over: Partial<App.Data.StayView.RoomRowData> = {},
) =>
    ({
        key,
        roomNumber,
        label: roomNumber,
        roomGroupId: 1,
        roomGroupName: 'Left wing',
        roomTypeId: 10,
        roomTypeName: 'Deluxe',
        ...over,
    }) as App.Data.StayView.RoomRowData;

const rooms = [
    room('room-1', '201'),
    room('room-2', '202', {
        roomGroupId: 2,
        roomGroupName: 'Right wing',
        roomTypeId: 20,
        roomTypeName: 'Suite',
    }),
    room('room-3', '203'),
    room('room-4', '204', { roomGroupId: null, roomGroupName: null }),
];

const shape = (rows: ReturnType<typeof buildRows>) =>
    rows.map((row) =>
        row.kind === 'group' ? `# ${row.title} (${row.count})` : row.key,
    );

test('groups by room group in the order they first appear, with a heading for rooms with none', () => {
    expect(shape(buildRows(rooms, 'room_group'))).toEqual([
        '# Left wing (2)',
        'room-1',
        'room-3',
        '# Right wing (1)',
        'room-2',
        '# No room group (1)',
        'room-4',
    ]);
});

test('groups by room type', () => {
    expect(shape(buildRows(rooms, 'room_type'))).toEqual([
        '# Deluxe (3)',
        'room-1',
        'room-3',
        'room-4',
        '# Suite (1)',
        'room-2',
    ]);
});

test('no grouping lists the rooms by number and keeps beds of a room together', () => {
    const beds = [
        room('bed-1', 'Dorm A'),
        room('room-5', '10'),
        room('bed-2', 'Dorm A'),
        room('room-6', '9'),
    ];

    expect(shape(buildRows(beds, 'none'))).toEqual([
        'room-6',
        'room-5',
        'bed-1',
        'bed-2',
    ]);
});

test('a collapsed group keeps its heading only', () => {
    const collapsed = toggleCollapsed(new Set(), 'group-1');

    expect(shape(buildRows(rooms, 'room_group', collapsed))).toEqual([
        '# Left wing (2)',
        '# Right wing (1)',
        'room-2',
        '# No room group (1)',
        'room-4',
    ]);
    expect([...toggleCollapsed(collapsed, 'group-1')]).toEqual([]);
});
