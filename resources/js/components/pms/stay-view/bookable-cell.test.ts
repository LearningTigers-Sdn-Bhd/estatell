import { expect, test } from 'vite-plus/test';
import { cellActions, isCellBookable, takenNights } from './bookable-cell';

const room = (
    over: Partial<App.Data.StayView.RoomRowData>,
): App.Data.StayView.RoomRowData =>
    ({
        key: 'room-1',
        roomId: 1,
        bedId: null,
        ...over,
    }) as App.Data.StayView.RoomRowData;

const stay = (
    rowKey: string,
    firstNight: string,
    lastNight: string,
): App.Data.StayView.StayData =>
    ({
        segments: [{ rowKey, firstNight, lastNight }],
    }) as App.Data.StayView.StayData;

const block = (
    over: Partial<App.Data.StayView.BlockData>,
): App.Data.StayView.BlockData =>
    ({
        roomId: 1,
        bedId: null,
        firstNight: '2026-10-15',
        lastNight: '2026-10-15',
        ...over,
    }) as App.Data.StayView.BlockData;

const dates = ['2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16'];
const base = {
    rowKey: 'room-1',
    businessDate: '2026-10-14',
};

test('an empty cell on the business date or later is bookable', () => {
    const taken = takenNights([room({})], [], [], dates);

    expect(isCellBookable({ ...base, date: '2026-10-14', taken })).toBe(true);
    expect(isCellBookable({ ...base, date: '2026-10-16', taken })).toBe(true);
});

test('a column before the business date is not bookable', () => {
    const taken = takenNights([room({})], [], [], dates);

    expect(isCellBookable({ ...base, date: '2026-10-13', taken })).toBe(false);
});

const both = {
    ...base,
    canManageReservations: true,
    canBlockRooms: true,
    today: '2026-10-14',
};

test('a cell offers a booking and a block to a user with both permissions', () => {
    const taken = takenNights([room({})], [], [], dates);

    expect(cellActions({ ...both, date: '2026-10-15', taken })).toEqual([
        'booking',
        'block',
    ]);
});

test('a cell offers only the action the user may take', () => {
    const taken = takenNights([room({})], [], [], dates);
    const at = (canManageReservations: boolean, canBlockRooms: boolean) =>
        cellActions({
            ...both,
            canManageReservations,
            canBlockRooms,
            date: '2026-10-15',
            taken,
        });

    expect(at(true, false)).toEqual(['booking']);
    expect(at(false, true)).toEqual(['block']);
    expect(at(false, false)).toEqual([]);
});

test('a block is not offered before the calendar date, while the business date is behind', () => {
    const taken = takenNights([room({})], [], [], dates);

    expect(
        cellActions({
            ...both,
            today: '2026-10-15',
            date: '2026-10-14',
            taken,
        }),
    ).toEqual(['booking']);
});

test('a taken cell offers nothing', () => {
    const taken = takenNights([room({})], [], [block({})], dates);

    expect(cellActions({ ...both, date: '2026-10-15', taken })).toEqual([]);
});

test('the departure day stays free and the nights of the stay are taken', () => {
    const taken = takenNights(
        [room({})],
        [stay('room-1', '2026-10-14', '2026-10-15')],
        [],
        dates,
    );

    expect(isCellBookable({ ...base, date: '2026-10-14', taken })).toBe(false);
    expect(isCellBookable({ ...base, date: '2026-10-15', taken })).toBe(false);
    expect(isCellBookable({ ...base, date: '2026-10-16', taken })).toBe(true);
});

test('a stay in another row leaves the cell bookable', () => {
    const taken = takenNights(
        [room({}), room({ key: 'room-2', roomId: 2 })],
        [stay('room-2', '2026-10-14', '2026-10-16')],
        [],
        dates,
    );

    expect(isCellBookable({ ...base, date: '2026-10-15', taken })).toBe(true);
});

test('a block on the room takes that night only', () => {
    const taken = takenNights([room({})], [], [block({})], dates);

    expect(isCellBookable({ ...base, date: '2026-10-15', taken })).toBe(false);
    expect(isCellBookable({ ...base, date: '2026-10-16', taken })).toBe(true);
});

test('a block on a dorm room takes every bed, a block on one bed takes only that bed', () => {
    const beds = [
        room({ key: 'bed-1', roomId: 5, bedId: 1 }),
        room({ key: 'bed-2', roomId: 5, bedId: 2 }),
    ];
    const roomBlock = takenNights(beds, [], [block({ roomId: 5 })], dates);
    const bedBlock = takenNights(
        beds,
        [],
        [block({ roomId: 5, bedId: 2 })],
        dates,
    );
    const at = (rowKey: string, taken: ReadonlySet<string>) =>
        isCellBookable({ ...base, rowKey, date: '2026-10-15', taken });

    expect(at('bed-1', roomBlock)).toBe(false);
    expect(at('bed-2', roomBlock)).toBe(false);
    expect(at('bed-1', bedBlock)).toBe(true);
    expect(at('bed-2', bedBlock)).toBe(false);
});
