import { expect, test } from 'vite-plus/test';
import { splitRoomTypes } from './room-type-overflow';

test('shows every name up to the limit', () => {
    expect(splitRoomTypes([])).toEqual({ shown: [], hidden: [] });
    expect(splitRoomTypes(['A', 'B', 'C'])).toEqual({
        shown: ['A', 'B', 'C'],
        hidden: [],
    });
});

test('folds the names past the limit into the hidden list', () => {
    expect(splitRoomTypes(['A', 'B', 'C', 'D', 'E'])).toEqual({
        shown: ['A', 'B', 'C'],
        hidden: ['D', 'E'],
    });
});
