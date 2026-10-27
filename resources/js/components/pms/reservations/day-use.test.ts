import { describe, expect, test } from 'vitest';
import { emptyLine } from './booking-lines';
import {
    dayUsePlanOptions,
    dayUseQuotePayload,
    dayUseRoomTypes,
    ENTERED_PLAN,
    endsAt,
    enteredEndsAt,
    enteredSwitchNote,
    hoursText,
    isDayUseQuotable,
    isEnteredPlan,
    isHalfHour,
    needsEnteredPrice,
    nextHalfHour,
    offerLabel,
    offerOptions,
    planIdFromSelect,
    planSelectValue,
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

const roomType = (id: number, enteredPlanId: number | null, isBed = false) =>
    ({
        id,
        name: `T${id}`,
        isBed,
        enteredPlanId,
    }) as App.Data.Reservations.RoomTypeOptionData;

test('day use room types are the per-room types, with or without a day use plan', () => {
    expect(
        dayUseRoomTypes([
            roomType(1, null),
            roomType(2, 20),
            roomType(3, null, true),
        ]).map((one) => one.id),
    ).toEqual([1, 2]);
});

describe('an entered price', () => {
    test('is the room type per-night plan', () => {
        expect(isEnteredPlan('20', roomType(2, 20))).toBe(true);
        expect(isEnteredPlan('21', roomType(2, 20))).toBe(false);
        expect(isEnteredPlan('', roomType(2, 20))).toBe(false);
        expect(isEnteredPlan('20', roomType(2, null))).toBe(false);
        expect(planSelectValue('20', roomType(2, 20))).toBe(ENTERED_PLAN);
        expect(planSelectValue('5', roomType(2, 20))).toBe('5');
        expect(planIdFromSelect(ENTERED_PLAN, roomType(2, 20))).toBe('20');
        expect(planIdFromSelect('5', roomType(2, 20))).toBe('5');
    });

    test('is a disabled option with its reason when the room type has no per-night plan', () => {
        const plans = [
            { id: 5, name: 'Day use (2–6h)' },
        ] as App.Data.Reservations.RatePlanOptionData[];

        expect(dayUsePlanOptions(plans, roomType(1, 20)).at(-1)).toEqual({
            value: ENTERED_PLAN,
            label: 'No plan · enter price',
        });
        expect(dayUsePlanOptions(plans, roomType(1, null)).at(-1)).toEqual({
            value: ENTERED_PLAN,
            label: 'No plan · enter price',
            disabled: true,
            description:
                'Add a per-night plan for this room type to enter a price.',
        });
        expect(dayUsePlanOptions(plans, roomType(1, 20))).toHaveLength(2);
    });

    test('ends at the start plus whole hours and never passes midnight', () => {
        expect(enteredEndsAt('02:00', '10')).toBe('12:00');
        expect(enteredEndsAt('10:30', '1')).toBe('11:30');
        expect(enteredEndsAt('14:00', '10')).toBeNull();
        expect(enteredEndsAt('14:00', '0')).toBeNull();
        expect(enteredEndsAt('14:00', '2.5')).toBeNull();
        expect(enteredEndsAt('', '2')).toBeNull();
    });

    test('is taken when the plan sells no hours at that start or not the hours picked', () => {
        expect(needsEnteredPrice(offers, false, '9')).toBe(false);
        expect(needsEnteredPrice([], true, '')).toBe(true);
        expect(needsEnteredPrice(offers, true, '')).toBe(false);
        expect(needsEnteredPrice(offers, true, '4')).toBe(false);
        expect(needsEnteredPrice(offers, true, '9')).toBe(true);
        expect(enteredSwitchNote('Day use (2–6h)')).toBe(
            'Outside what Day use (2–6h) sells. Enter the price.',
        );
    });
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
                { ...line, child_ages: ['4'], override_total: '80.00' },
                '2026-10-12',
                '10:00',
            ),
        ).toMatchObject({
            stay_type: 'day_use',
            arrival_date: '2026-10-12',
            start_time: '10:00',
            hours: '4',
            child_ages: [4],
            override_total: '80.00',
        });
    });

    test('leaves an invalid override out of the quote', () => {
        expect(
            dayUseQuotePayload(
                { ...line, override_total: '-1' },
                '2026-10-12',
                '10:00',
            ).override_total,
        ).toBe('');
    });
});
