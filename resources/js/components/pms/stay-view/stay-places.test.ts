import { expect, test } from 'vite-plus/test';
import { groupMates, nightCount, placeLabel, placesOf } from './stay-places';

const room = (
    over: Partial<App.Data.StayView.RoomRowData>,
): App.Data.StayView.RoomRowData =>
    ({
        key: 'room-1',
        bedId: null,
        label: '201',
        roomNumber: '201',
        roomTypeName: 'Deluxe',
        ...over,
    }) as App.Data.StayView.RoomRowData;

const stay = (
    over: Partial<App.Data.StayView.StayData>,
): App.Data.StayView.StayData =>
    ({
        unitId: 1,
        reservationId: 10,
        groupName: null,
        arrivalDate: '2026-10-14',
        segments: [],
        ...over,
    }) as App.Data.StayView.StayData;

test('names a room with its type and a bed with its room', () => {
    expect(placeLabel(room({}))).toBe('201 Deluxe');
    expect(
        placeLabel(
            room({ key: 'bed-3', bedId: 3, label: 'A1', roomNumber: 'Dorm A' }),
        ),
    ).toBe('Bed A1 · Dorm A');
});

test('lists the places of a stay in date order and keeps one whose row is hidden', () => {
    const places = placesOf(
        stay({
            segments: [
                {
                    rowKey: 'room-2',
                    firstNight: '2026-10-16',
                    lastNight: '2026-10-16',
                },
                {
                    rowKey: 'room-1',
                    firstNight: '2026-10-14',
                    lastNight: '2026-10-15',
                },
            ],
        }),
        [room({ key: 'room-1' })],
    );

    expect(places).toEqual([
        {
            label: '201 Deluxe',
            firstNight: '2026-10-14',
            lastNight: '2026-10-15',
        },
        {
            label: 'Unknown room',
            firstNight: '2026-10-16',
            lastNight: '2026-10-16',
        },
    ]);
});

test('counts nights across a month end', () => {
    expect(nightCount('2026-10-30', '2026-11-02')).toBe(3);
    expect(nightCount('2026-10-14', '2026-10-15')).toBe(1);
});

test('lists the loaded units of a group booking by arrival, and none for a single stay', () => {
    const stays = [
        stay({
            unitId: 3,
            reservationId: 10,
            groupName: 'ABC Corp',
            arrivalDate: '2026-10-15',
        }),
        stay({
            unitId: 2,
            reservationId: 10,
            groupName: 'ABC Corp',
            arrivalDate: '2026-10-14',
        }),
        stay({ unitId: 4, reservationId: 11, groupName: 'XYZ' }),
        stay({ unitId: 5, reservationId: 12 }),
    ];

    expect(groupMates(stays, stays[0]).map((item) => item.unitId)).toEqual([
        2, 3,
    ]);
    expect(groupMates(stays, stays[3])).toEqual([]);
});
