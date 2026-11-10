import { expect, test } from 'vite-plus/test';
import {
    DEFAULT_SCREEN_COLUMNS,
    mergeScreenColumns,
} from './use-screen-columns';

test('nothing stored gives the board defaults', () => {
    expect(mergeScreenColumns(null)).toEqual(DEFAULT_SCREEN_COLUMNS);
    expect(mergeScreenColumns('broken')).toEqual(DEFAULT_SCREEN_COLUMNS);
});

test('stored columns keep their order, stale keys drop and a new column goes after the one before it', () => {
    const merged = mergeScreenColumns([
        { key: 'status', visible: true },
        { key: 'room', visible: true },
        { key: 'old', visible: true },
        { key: 'guest', visible: false },
        { key: 'pax', visible: true },
        { key: 'arrival', visible: true },
        { key: 'departure', visible: true },
        { key: 'assigned', visible: true },
        { key: 'remarks', visible: true },
    ]);

    expect(merged.map((column) => column.key)).toEqual([
        'status',
        'room',
        'guest',
        'stay',
        'pax',
        'arrival',
        'departure',
        'assigned',
        'remarks',
    ]);
    expect(merged.find((column) => column.key === 'guest')?.visible).toBe(
        false,
    );
    expect(merged.find((column) => column.key === 'stay')?.visible).toBe(true);
});

test('Room and Status cannot be hidden', () => {
    const merged = mergeScreenColumns([
        { key: 'room', visible: false },
        { key: 'status', visible: false },
    ]);

    expect(
        merged.filter(
            (column) => column.key === 'room' || column.key === 'status',
        ),
    ).toEqual([
        { key: 'room', visible: true },
        { key: 'status', visible: true },
    ]);
});
