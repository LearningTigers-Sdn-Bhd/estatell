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

const lanes = [
    {
        roomTypeId: 20,
        roomTypeName: 'Suite',
        rowKeys: ['unassigned-20-0', 'unassigned-20-1'],
        units: 3,
    },
    {
        roomTypeId: 30,
        roomTypeName: 'Family',
        rowKeys: ['unassigned-30-0'],
        units: 1,
    },
];

test('by room type the lanes sit at the top of their group, and a type with no room gets a group', () => {
    const rows = buildRows(rooms, 'room_type', new Set(), lanes);

    expect(rows.map((row) => row.key)).toEqual([
        'type-10',
        'room-1',
        'room-3',
        'room-4',
        'type-20',
        'unassigned-20-0',
        'unassigned-20-1',
        'room-2',
        'type-30',
        'unassigned-30-0',
    ]);
    expect(rows.find((row) => row.key === 'unassigned-20-0')).toMatchObject({
        room: null,
        lane: { roomTypeName: 'Suite', units: 3 },
    });
    expect(rows.find((row) => row.key === 'unassigned-20-1')).toMatchObject({
        lane: { units: null },
    });
});

test('by room group the lanes share one Unassigned group at the top', () => {
    const rows = buildRows(rooms, 'room_group', new Set(), lanes);

    expect(rows.slice(0, 5).map((row) => row.key)).toEqual([
        'unassigned',
        'unassigned-20-0',
        'unassigned-20-1',
        'unassigned-30-0',
        'group-1',
    ]);
    expect(rows[0]).toMatchObject({ title: 'Unassigned', count: 4 });
    expect(
        buildRows(rooms, 'room_group', new Set(['unassigned']), lanes)
            .slice(0, 2)
            .map((row) => row.key),
    ).toEqual(['unassigned', 'group-1']);
});

test('with no grouping the lanes are a group and the rooms follow under All rooms', () => {
    const rows = buildRows(rooms, 'none', new Set(), lanes);

    expect(rows.map((row) => row.key)).toEqual([
        'unassigned',
        'unassigned-20-0',
        'unassigned-20-1',
        'unassigned-30-0',
        'all-rooms',
        'room-1',
        'room-2',
        'room-3',
        'room-4',
    ]);
    expect(buildRows(rooms, 'none').map((row) => row.key)).toEqual([
        'room-1',
        'room-2',
        'room-3',
        'room-4',
    ]);
});
