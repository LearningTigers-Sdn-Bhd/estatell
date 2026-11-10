import { expect, test } from 'vite-plus/test';
import { openingGrouping } from './use-stay-view-grouping';

test('a group_by in the URL wins over the stored choice', () => {
    expect(openingGrouping('room_type', 'none', 'room_group')).toBe(
        'room_type',
    );
});

test('the stored choice is used when the URL has none', () => {
    expect(openingGrouping(null, 'none', 'room_group')).toBe('none');
});

test('the server value is used when nothing is stored', () => {
    expect(openingGrouping(null, null, 'room_group')).toBe('room_group');
});

test('unknown values are ignored', () => {
    expect(openingGrouping('bogus', 'floor', 'room_group')).toBe('room_group');
    expect(openingGrouping('bogus', 'room_type', 'room_group')).toBe(
        'room_type',
    );
});
