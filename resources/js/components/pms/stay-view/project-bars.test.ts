import { expect, test } from 'vite-plus/test';
import {
    barBox,
    blockBarInputs,
    lastNightOf,
    projectBars,
    stayBarInputs,
} from './project-bars';

const dates = ['2026-10-14', '2026-10-15', '2026-10-16', '2026-10-17'];

test('places a bar by its first column and span', () => {
    const bars = projectBars(
        [
            {
                id: 'a',
                rowKey: 'room-1',
                firstNight: '2026-10-15',
                lastNight: '2026-10-16',
            },
        ],
        dates,
    );

    expect(bars).toEqual([
        {
            id: 'a',
            rowKey: 'room-1',
            start: 1,
            span: 2,
            openStart: false,
            openEnd: false,
            leftShift: 0,
            rightShift: 0,
        },
    ]);
});

test('cuts a bar at the window and marks the open edge only when the stay goes on', () => {
    const [bar] = projectBars(
        [
            {
                id: 'a',
                rowKey: 'room-1',
                firstNight: '2026-10-12',
                lastNight: '2026-10-20',
                stayFirstNight: '2026-10-12',
                stayLastNight: '2026-10-20',
            },
        ],
        dates,
    );

    expect(bar).toMatchObject({
        start: 0,
        span: 4,
        openStart: true,
        openEnd: true,
    });
});

test('a segment that starts a room move at the window edge is not open', () => {
    const [bar] = projectBars(
        [
            {
                id: 'a',
                rowKey: 'room-2',
                firstNight: '2026-10-14',
                lastNight: '2026-10-15',
                stayFirstNight: '2026-10-14',
                stayLastNight: '2026-10-15',
            },
        ],
        dates,
    );

    expect(bar).toMatchObject({ openStart: false, openEnd: false });
});

test('leaves out a bar outside the dates', () => {
    expect(
        projectBars(
            [
                {
                    id: 'a',
                    rowKey: 'room-1',
                    firstNight: '2026-10-01',
                    lastNight: '2026-10-13',
                },
                {
                    id: 'b',
                    rowKey: 'room-1',
                    firstNight: '2026-10-18',
                    lastNight: '2026-10-19',
                },
            ],
            dates,
        ),
    ).toEqual([]);
    expect(projectBars([], [])).toEqual([]);
});

test('the last night is the day before departure, across a month end', () => {
    expect(lastNightOf('2026-10-17')).toBe('2026-10-16');
    expect(lastNightOf('2026-11-01')).toBe('2026-10-31');
});

test('a stay gives a bar for each segment, with the whole stay as its edges', () => {
    const inputs = stayBarInputs([
        {
            unitId: 7,
            arrivalDate: '2026-10-12',
            departureDate: '2026-10-17',
            segments: [
                {
                    rowKey: 'room-1',
                    firstNight: '2026-10-14',
                    lastNight: '2026-10-15',
                },
                {
                    rowKey: 'room-2',
                    firstNight: '2026-10-16',
                    lastNight: '2026-10-16',
                },
            ],
        } as App.Data.StayView.StayData,
    ]);

    expect(inputs.map((input) => input.id)).toEqual(['stay-7-0', 'stay-7-1']);
    expect(inputs[0]).toMatchObject({
        stayFirstNight: '2026-10-12',
        stayLastNight: '2026-10-16',
    });
});

test('a room block takes every bed of a bed room, and a bed block takes one bed', () => {
    const rooms = [
        { key: 'bed-1', roomId: 9, bedId: 1 },
        { key: 'bed-2', roomId: 9, bedId: 2 },
        { key: 'room-3', roomId: 3, bedId: null },
    ] as App.Data.StayView.RoomRowData[];
    const block = {
        id: 5,
        firstNight: '2026-10-14',
        lastNight: '2026-10-15',
    };

    expect(
        blockBarInputs(
            [
                {
                    ...block,
                    roomId: 9,
                    bedId: null,
                } as App.Data.StayView.BlockData,
            ],
            rooms,
        ).map((input) => input.rowKey),
    ).toEqual(['bed-1', 'bed-2']);
    expect(
        blockBarInputs(
            [{ ...block, roomId: 9, bedId: 2 } as App.Data.StayView.BlockData],
            rooms,
        ).map((input) => input.rowKey),
    ).toEqual(['bed-2']);
    expect(
        blockBarInputs(
            [
                {
                    ...block,
                    roomId: 3,
                    bedId: null,
                } as App.Data.StayView.BlockData,
            ],
            rooms,
        ).map((input) => input.rowKey),
    ).toEqual(['room-3']);
});

test('a half-cell bar starts and ends in the middle of the arrival and departure columns', () => {
    const [bar] = projectBars(
        [
            {
                id: 'a',
                rowKey: 'room-1',
                firstNight: '2026-10-15',
                lastNight: '2026-10-15',
                halfCells: true,
            },
        ],
        dates,
    );

    expect(bar).toMatchObject({ leftShift: 0.5, rightShift: 0.5 });
    expect(barBox(bar, 100, 80)).toEqual({ left: 100 + 1.5 * 80, width: 80 });
});

test('a half-cell bar is flush at the window edges', () => {
    const [bar] = projectBars(
        [
            {
                id: 'a',
                rowKey: 'room-1',
                firstNight: '2026-10-12',
                lastNight: '2026-10-17',
                halfCells: true,
            },
        ],
        dates,
    );

    expect(bar).toMatchObject({ leftShift: 0, rightShift: 0 });
    expect(barBox(bar, 0, 10)).toEqual({ left: 0, width: 40 });
});

test('a departure and an arrival on one day fill the two halves of that column', () => {
    const [out, into] = projectBars(
        [
            {
                id: 'a',
                rowKey: 'room-1',
                firstNight: '2026-10-14',
                lastNight: '2026-10-15',
                halfCells: true,
            },
            {
                id: 'b',
                rowKey: 'room-1',
                firstNight: '2026-10-16',
                lastNight: '2026-10-16',
                halfCells: true,
            },
        ],
        dates,
    );

    const first = barBox(out, 0, 10);
    const second = barBox(into, 0, 10);

    expect(first.left + first.width).toBe(25);
    expect(second.left).toBe(25);
});
