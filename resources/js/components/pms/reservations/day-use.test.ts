import { describe, expect, test } from 'vitest';
import { emptyLine } from './booking-lines';
import {
    dayUseQuotePayload,
    dayUseRoomTypes,
    endsAt,
    hoursText,
    isDayUseQuotable,
    isHalfHour,
    nextHalfHour,
    offerLabel,
    offerOptions,
} from './day-use';

const offers = [
    { hours: 1, amount: '40.00', endsAt: '11:00' },
    { hours: 4, amount: '95.00', endsAt: '14:00' },
];
const money = (amount: string) => `MYR ${amount}`;

describe('nextHalfHour', () => {
    test.each([
        ['10:12', '10:30'],
        ['10:30', '11:00'],
        ['10:00', '10:30'],
        ['23:45', '23:30'],
        ['00:00', '00:30'],
        ['09:59', '10:00'],
    ])('%s gives %s', (time, expected) => {
        expect(nextHalfHour(time)).toBe(expected);
    });

    test('a bad time gives nothing', () => {
        expect(nextHalfHour('')).toBe('');
        expect(nextHalfHour('soon')).toBe('');
    });
});

test('only the hour and the half hour are starts', () => {
    expect(isHalfHour('10:00')).toBe(true);
    expect(isHalfHour('10:30')).toBe(true);
    expect(isHalfHour('10:15')).toBe(false);
    expect(isHalfHour('')).toBe(false);
});

test('hours are labelled with their price and end', () => {
    expect(hoursText(1)).toBe('1 hour');
    expect(hoursText(4)).toBe('4 hours');
    expect(offerLabel(offers[1], money)).toBe('4 hours · MYR 95.00');
    expect(offerOptions(offers, money)).toEqual([
        { value: '1', label: '1 hour · MYR 40.00' },
        { value: '4', label: '4 hours · MYR 95.00' },
    ]);
    expect(endsAt(offers, '4')).toBe('14:00');
    expect(endsAt(offers, '9')).toBeNull();
    expect(endsAt(offers, '')).toBeNull();
});

test('day use room types are the per-room types with a day use plan', () => {
    const type = (id: number, isBed = false) =>
        ({
            id,
            name: `T${id}`,
            isBed,
        }) as App.Data.Reservations.RoomTypeOptionData;
    const plan = (roomTypeIds: number[], isDayUse: boolean) =>
        ({
            id: roomTypeIds[0] * 10,
            roomTypeIds,
            isDayUse,
        }) as App.Data.Reservations.RatePlanOptionData;

    expect(
        dayUseRoomTypes(
            [type(1), type(2), type(3, true)],
            [plan([1, 3], true), plan([2], false)],
        ).map((one) => one.id),
    ).toEqual([1]);
});

describe('a day use line', () => {
    const line = {
        ...emptyLine(),
        room_type_id: '1',
        rate_plan_id: '2',
        hours: '4',
    };

    test('is quotable when it has the plan, hours, date and a start on the half hour', () => {
        expect(isDayUseQuotable(line, '2026-10-12', '10:00')).toBe(true);
        expect(
            isDayUseQuotable({ ...line, hours: '' }, '2026-10-12', '10:00'),
        ).toBe(false);
        expect(isDayUseQuotable(line, '', '10:00')).toBe(false);
        expect(isDayUseQuotable(line, '2026-10-12', '10:10')).toBe(false);
        expect(
            isDayUseQuotable(
                { ...line, child_ages: [''] },
                '2026-10-12',
                '10:00',
            ),
        ).toBe(false);
    });

    test('sends the day use keys', () => {
        expect(
            dayUseQuotePayload(
                { ...line, child_ages: ['4'] },
                '2026-10-12',
                '10:00',
            ),
        ).toMatchObject({
            stay_type: 'day_use',
            arrival_date: '2026-10-12',
            start_time: '10:00',
            hours: '4',
            child_ages: [4],
        });
    });
});
