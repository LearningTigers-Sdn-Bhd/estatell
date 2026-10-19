import { describe, expect, it } from 'vite-plus/test';
import {
    nightsBetween,
    offerLines,
    resizeAges,
    shiftDate,
    sumAmounts,
    unitSlots,
} from './book-lines';
import type { BookLine } from './book-lines';

const line = (overrides: Partial<BookLine>): BookLine => ({
    key: 'k',
    rate_plan_id: '7',
    room_type_id: '1',
    rooms: '1',
    adults: '2',
    child_ages: [],
    beds: '1',
    ...overrides,
});

const isDorm = (id: string) => id === '2';
const names: Record<string, string> = { '1': 'Deluxe King', '2': 'Dorm' };

describe('book lines', () => {
    it('keeps ages when the child count changes and asks for the new ones', () => {
        expect(resizeAges(['4', '9'], 3)).toEqual(['4', '9', '']);
        expect(resizeAges(['4', '9'], 1)).toEqual(['4']);
        expect(resizeAges([], 0)).toEqual([]);
    });

    it('asks for offers with the plan, the rooms and guests, and a dorm line with its beds', () => {
        const body = offerLines(
            [
                line({ rooms: '2', child_ages: ['6', ''] }),
                line({ room_type_id: '2', beds: '8', adults: '5' }),
                line({ rate_plan_id: '', room_type_id: '' }),
            ],
            isDorm,
        );

        expect(body[0]).toEqual({
            rate_plan_id: 7,
            room_type_id: 1,
            rooms: 2,
            adults: 2,
            child_ages: [6, null],
            beds: 1,
        });
        expect(body[1]).toEqual({
            rate_plan_id: 7,
            room_type_id: 2,
            rooms: 1,
            adults: 1,
            child_ages: [],
            beds: 8,
        });
        expect(body[2].rate_plan_id).toBeNull();
        expect(body[2].room_type_id).toBeNull();
    });

    it('lists one unit for each room and bed, numbering rooms per room type', () => {
        const slots = unitSlots(
            [
                line({ key: 'a', rooms: '2' }),
                line({ key: 'b', room_type_id: '2', beds: '2' }),
                line({ key: 'c' }),
                line({ key: 'd', room_type_id: '' }),
            ],
            (id) => names[id],
            isDorm,
        );

        expect(slots.map((slot) => slot.label)).toEqual([
            'Deluxe King #1',
            'Deluxe King #2',
            'Dorm · Bed 1',
            'Dorm · Bed 2',
            'Deluxe King #3',
        ]);
        expect(slots.map((slot) => slot.key)).toEqual([
            'a:0',
            'a:1',
            'b:0',
            'b:1',
            'c:0',
        ]);
    });

    it('adds money in cents', () => {
        expect(sumAmounts(['0.10', '0.20', '1280.00', null])).toBe('1280.30');
        expect(sumAmounts([])).toBe('0.00');
    });

    it('counts nights and shifts dates across a month end', () => {
        expect(nightsBetween('2026-10-21', '2026-10-23')).toBe(2);
        expect(nightsBetween('2026-10-23', '2026-10-21')).toBe(0);
        expect(shiftDate('2026-11-02', 7)).toBe('2026-11-09');
        expect(shiftDate('2026-11-02', -3)).toBe('2026-10-30');
    });
});
