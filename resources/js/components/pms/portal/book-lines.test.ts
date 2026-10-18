import { describe, expect, it } from 'vite-plus/test';
import {
    nightsBetween,
    resizeAges,
    searchLines,
    shiftDate,
    sumAmounts,
} from './book-lines';
import type { BookLine } from './book-lines';

const line = (overrides: Partial<BookLine>): BookLine => ({
    key: 'k',
    room_type_id: '1',
    adults: '2',
    child_ages: [],
    beds: '1',
    rate_plan_id: '',
    guest_name: '',
    guest_phone: '',
    ...overrides,
});

describe('book lines', () => {
    it('keeps ages when the child count changes and asks for the new ones', () => {
        expect(resizeAges(['4', '9'], 3)).toEqual(['4', '9', '']);
        expect(resizeAges(['4', '9'], 1)).toEqual(['4']);
        expect(resizeAges([], 0)).toEqual([]);
    });

    it('searches a room line with its guests and a dorm line with its beds', () => {
        const body = searchLines(
            [
                line({ child_ages: ['6', ''] }),
                line({ room_type_id: '2', beds: '8', adults: '5' }),
            ],
            (id) => id === '2',
        );

        expect(body[0]).toEqual({
            room_type_id: 1,
            adults: 2,
            child_ages: [6, null],
            beds: 1,
        });
        expect(body[1]).toEqual({
            room_type_id: 2,
            adults: 1,
            child_ages: [],
            beds: 8,
        });
    });

    it('adds money in cents', () => {
        expect(sumAmounts(['0.10', '0.20', '1280.00'])).toBe('1280.30');
        expect(sumAmounts([])).toBe('0.00');
    });

    it('counts nights and shifts dates across a month end', () => {
        expect(nightsBetween('2026-10-21', '2026-10-23')).toBe(2);
        expect(nightsBetween('2026-10-23', '2026-10-21')).toBe(0);
        expect(shiftDate('2026-11-02', 7)).toBe('2026-11-09');
        expect(shiftDate('2026-11-02', -3)).toBe('2026-10-30');
    });
});
