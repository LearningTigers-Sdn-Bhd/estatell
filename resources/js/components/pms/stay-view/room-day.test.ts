import { expect, test } from 'vite-plus/test';
import { roomDays, showStates, summarizeDay } from './room-day';

const date = '2026-10-14';

const rooms = ['room-1', 'room-2', 'bed-3', 'bed-4', 'room-5'].map(
    (key, index) =>
        ({
            key,
            roomId: key.startsWith('bed') ? 9 : index + 1,
            bedId: key.startsWith('bed') ? index + 1 : null,
        }) as App.Data.StayView.RoomRowData,
);

const stay = (
    unitId: number,
    arrivalDate: string,
    departureDate: string,
    segments: [string, string, string][],
) =>
    ({
        unitId,
        arrivalDate,
        departureDate,
        segments: segments.map(([rowKey, firstNight, lastNight]) => ({
            rowKey,
            firstNight,
            lastNight,
        })),
    }) as App.Data.StayView.StayData;

const block = (
    id: number,
    roomId: number,
    bedId: number | null,
    firstNight: string,
    lastNight: string,
) =>
    ({
        id,
        roomId,
        bedId,
        firstNight,
        lastNight,
    }) as App.Data.StayView.BlockData;

const stateOf = (days: ReturnType<typeof roomDays>, key: string) =>
    days.find((day) => day.room.key === key)?.states;

test('gives each row its state on the date', () => {
    const days = roomDays(
        rooms,
        [
            stay(1, '2026-10-12', '2026-10-14', [
                ['room-1', '2026-10-12', '2026-10-13'],
            ]),
            stay(2, '2026-10-14', '2026-10-16', [
                ['room-2', '2026-10-14', '2026-10-15'],
            ]),
            stay(3, '2026-10-13', '2026-10-16', [
                ['bed-3', '2026-10-13', '2026-10-15'],
            ]),
        ],
        [block(1, 9, 4, '2026-10-10', '2026-10-20')],
        date,
    );

    expect(stateOf(days, 'room-1')).toEqual(['departing']);
    expect(stateOf(days, 'room-2')).toEqual(['arriving']);
    expect(stateOf(days, 'bed-3')).toEqual(['in_house']);
    expect(stateOf(days, 'bed-4')).toEqual(['blocked']);
    expect(stateOf(days, 'room-5')).toEqual(['vacant']);
});

test('a room with a departure and an arrival has both, departing first', () => {
    const days = roomDays(
        rooms,
        [
            stay(2, '2026-10-14', '2026-10-16', [
                ['room-1', '2026-10-14', '2026-10-15'],
            ]),
            stay(1, '2026-10-12', '2026-10-14', [
                ['room-1', '2026-10-12', '2026-10-13'],
            ]),
        ],
        [],
        date,
    );
    const day = days[0];

    expect(day.states).toEqual(['departing', 'arriving']);
    expect(day.stays.map((line) => [line.stay.unitId, line.state])).toEqual([
        [1, 'departing'],
        [2, 'arriving'],
    ]);
});

test('a stay that moved room is in house in the new room and leaves the old one vacant', () => {
    const days = roomDays(
        rooms,
        [
            stay(1, '2026-10-12', '2026-10-16', [
                ['room-1', '2026-10-12', '2026-10-13'],
                ['room-2', '2026-10-14', '2026-10-15'],
            ]),
        ],
        [],
        date,
    );

    expect(stateOf(days, 'room-1')).toEqual(['vacant']);
    expect(stateOf(days, 'room-2')).toEqual(['in_house']);
});

test('a stay that moves on its last night departs from the last room only', () => {
    const days = roomDays(
        rooms,
        [
            stay(1, '2026-10-12', '2026-10-14', [
                ['room-1', '2026-10-12', '2026-10-12'],
                ['room-2', '2026-10-13', '2026-10-13'],
            ]),
        ],
        [],
        date,
    );

    expect(stateOf(days, 'room-1')).toEqual(['vacant']);
    expect(stateOf(days, 'room-2')).toEqual(['departing']);
});

test('a block takes every bed of its room, and only blocks that cover the date count', () => {
    const days = roomDays(
        rooms,
        [],
        [
            block(1, 9, null, '2026-10-14', '2026-10-14'),
            block(2, 1, null, '2026-10-15', '2026-10-20'),
            block(3, 2, null, '2026-10-01', '2026-10-13'),
        ],
        date,
    );

    expect(stateOf(days, 'bed-3')).toEqual(['blocked']);
    expect(stateOf(days, 'bed-4')).toEqual(['blocked']);
    expect(stateOf(days, 'room-1')).toEqual(['vacant']);
    expect(stateOf(days, 'room-2')).toEqual(['vacant']);
    expect(days[2].block?.id).toBe(1);
});

test('a block that starts the day a guest leaves shows both', () => {
    const days = roomDays(
        rooms,
        [
            stay(1, '2026-10-12', '2026-10-14', [
                ['room-1', '2026-10-12', '2026-10-13'],
            ]),
        ],
        [block(1, 1, null, '2026-10-14', '2026-10-16')],
        date,
    );

    expect(days[0].states).toEqual(['departing', 'blocked']);
});

test('ignores stays with no room yet', () => {
    const days = roomDays(
        rooms,
        [
            stay(1, '2026-10-14', '2026-10-15', [
                ['unassigned-10', '2026-10-14', '2026-10-14'],
            ]),
        ],
        [],
        date,
    );

    expect(days.every((day) => day.states[0] === 'vacant')).toBe(true);
});

test('counts the cards in each state and filters by Show', () => {
    const days = roomDays(
        rooms,
        [
            stay(1, '2026-10-12', '2026-10-14', [
                ['room-1', '2026-10-12', '2026-10-13'],
            ]),
            stay(2, '2026-10-14', '2026-10-16', [
                ['room-1', '2026-10-14', '2026-10-15'],
            ]),
            stay(3, '2026-10-13', '2026-10-16', [
                ['bed-3', '2026-10-13', '2026-10-15'],
            ]),
        ],
        [block(1, 2, null, '2026-10-14', '2026-10-14')],
        date,
    );

    expect(summarizeDay(days)).toEqual({
        arriving: 1,
        in_house: 1,
        departing: 1,
        vacant: 2,
        blocked: 1,
    });
    expect(showStates(days, [])).toHaveLength(5);
    expect(
        showStates(days, ['departing', 'vacant']).map((day) => day.room.key),
    ).toEqual(['room-1', 'bed-4', 'room-5']);
});
