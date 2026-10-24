import { expect, test } from 'vite-plus/test';
import { summarizeInventory } from './summarize-inventory';

const rooms = ['room-1', 'room-2', 'bed-3', 'bed-4'].map((key, index) => ({
    key,
    roomId: key.startsWith('bed') ? 9 : index + 1,
    bedId: key.startsWith('bed') ? index + 1 : null,
})) as App.Data.StayView.RoomRowData[];

const dates = ['2026-10-14', '2026-10-15'];

const stay = (rowKey: string, firstNight: string, lastNight: string) =>
    ({
        segments: [{ rowKey, firstNight, lastNight }],
    }) as App.Data.StayView.StayData;

test('counts occupied, blocked and available rows for each date', () => {
    const days = summarizeInventory(
        rooms,
        [
            stay('room-1', '2026-10-14', '2026-10-15'),
            stay('bed-3', '2026-10-15', '2026-10-15'),
        ],
        [
            {
                id: 1,
                roomId: 2,
                bedId: null,
                firstNight: '2026-10-14',
                lastNight: '2026-10-14',
            } as App.Data.StayView.BlockData,
        ],
        dates,
    );

    expect(days[0]).toEqual({
        date: '2026-10-14',
        total: 4,
        occupied: 1,
        blocked: 1,
        available: 2,
        occupancy: 33,
    });
    expect(days[1]).toMatchObject({
        occupied: 2,
        blocked: 0,
        available: 2,
        occupancy: 50,
    });
});

test('a block on a bed room takes every bed of it', () => {
    const [day] = summarizeInventory(
        rooms,
        [],
        [
            {
                id: 1,
                roomId: 9,
                bedId: null,
                firstNight: '2026-10-14',
                lastNight: '2026-10-14',
            } as App.Data.StayView.BlockData,
        ],
        dates,
    );

    expect(day).toMatchObject({ blocked: 2, available: 2, occupancy: 0 });
});

test('a stay in a hidden row is not counted and an empty grid has no occupancy', () => {
    const [day] = summarizeInventory(
        [rooms[0]],
        [stay('room-2', '2026-10-14', '2026-10-14')],
        [],
        dates,
    );

    expect(day).toMatchObject({ occupied: 0, available: 1 });
    expect(summarizeInventory([], [], [], dates)[0]).toMatchObject({
        total: 0,
        available: 0,
        occupancy: 0,
    });
});
