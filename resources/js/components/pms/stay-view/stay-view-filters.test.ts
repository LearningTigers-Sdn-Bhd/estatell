import { describe, expect, it } from 'vitest';
import { activeFilterCount, clearedFilters } from './stay-view-filters';

const none = {
    roomGroupIds: [],
    roomTypeIds: [],
    statuses: [],
    search: '',
    groupBy: 'room_group',
} as unknown as App.Data.StayView.StayViewFiltersData;

describe('activeFilterCount', () => {
    it('is 0 when nothing is set', () => {
        expect(activeFilterCount(none)).toBe(0);
    });

    it('counts each picked item and a search with text', () => {
        expect(
            activeFilterCount({
                ...none,
                roomGroupIds: [1, 2],
                statuses: ['dirty' as never],
                search: 'ahmad',
            }),
        ).toBe(4);
    });

    it('ignores a search of spaces', () => {
        expect(activeFilterCount({ ...none, search: '   ' })).toBe(0);
    });
});

describe('clearedFilters', () => {
    it('clears the filters and keeps Group by', () => {
        const cleared = clearedFilters({
            ...none,
            roomTypeIds: [3],
            search: 'x',
            groupBy: 'room_type' as never,
        });

        expect(activeFilterCount(cleared)).toBe(0);
        expect(cleared.groupBy).toBe('room_type');
    });
});
