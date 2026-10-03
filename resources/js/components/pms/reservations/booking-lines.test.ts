import { expect, test } from 'vite-plus/test';
import {
    addDays,
    bookingPayload,
    emptyLine,
    isQuotable,
    moveAddOn,
    nightsBetween,
    resizeAges,
    resizeBeds,
    sumMoney,
    totalUnits,
    unitsInLine,
} from './booking-lines';
import type { BookingForm } from './booking-lines';

const roomType = {
    id: 1,
    name: 'Deluxe',
    isBed: false,
    maxAdults: 2,
    maxChildren: 2,
};
const dorm = { id: 2, name: 'Dorm', isBed: true, maxAdults: 1, maxChildren: 0 };

test('a dorm line adds one unit for each bed and a room line adds one', () => {
    const room = { ...emptyLine(), room_type_id: '1' };
    const beds = { ...emptyLine(), room_type_id: '2', beds: '3' };

    expect(unitsInLine(room, [roomType, dorm])).toBe(1);
    expect(unitsInLine(beds, [roomType, dorm])).toBe(3);
    expect(unitsInLine({ ...beds, beds: 'x' }, [roomType, dorm])).toBe(1);
    expect(totalUnits([room, beds], [roomType, dorm])).toBe(4);
});

test('dates add up without a time zone shift', () => {
    expect(addDays('2026-10-30', 3)).toBe('2026-11-02');
    expect(nightsBetween('2026-10-20', '2026-10-23')).toBe(3);
    expect(nightsBetween('2026-10-20', '2026-10-20')).toBe(0);
    expect(nightsBetween('', '2026-10-20')).toBe(0);
});

test('amounts are added in cents', () => {
    expect(sumMoney(['0.10', '0.20', null, undefined, '840.00'])).toBe(
        '840.30',
    );
    expect(sumMoney([])).toBe('0.00');
});

test('ages and beds keep what was typed when the count changes', () => {
    expect(resizeAges(['4', '9'], 3)).toEqual(['4', '9', '']);
    expect(resizeAges(['4', '9'], 1)).toEqual(['4']);
    expect(resizeBeds(['7'], 3)).toEqual(['7', '', '']);
    expect(resizeBeds([], 0)).toEqual(['']);
});

test('a line is quotable only with its plan, nights, adults and every child age', () => {
    const line = { ...emptyLine(), room_type_id: '1', rate_plan_id: '4' };

    expect(isQuotable(line, '2026-10-20', '2026-10-22')).toBe(true);
    expect(isQuotable(line, '2026-10-20', '')).toBe(false);
    expect(
        isQuotable({ ...line, rate_plan_id: '' }, '2026-10-20', '2026-10-22'),
    ).toBe(false);
    expect(
        isQuotable({ ...line, child_ages: [''] }, '2026-10-20', '2026-10-22'),
    ).toBe(false);
    expect(
        isQuotable({ ...line, adults: '0' }, '2026-10-20', '2026-10-22'),
    ).toBe(false);
});

test('the posted lines drop the client key and send dorm beds instead of adults and children', () => {
    const room = {
        ...emptyLine(),
        room_type_id: '1',
        rate_plan_id: '4',
        child_ages: ['4'],
        room_id: '9',
    };
    const beds = {
        ...emptyLine(),
        room_type_id: '2',
        rate_plan_id: '5',
        beds: '2',
        bed_ids: ['3', ''],
        adults: '5',
        room_id: '8',
    };
    const data: BookingForm = {
        source: 'phone',
        external_reference: '',
        arrival_date: '2026-10-20',
        departure_date: '2026-10-22',
        arrival_time: '15:00',
        departure_time: '11:00',
        group_name: '',
        billing_mode: 'master_pays',
        notes: '',
        guests: [],
        lines: [room, beds],
    };

    const [first, second] = bookingPayload(data, [roomType, dorm]).lines;

    expect(first).not.toHaveProperty('key');
    expect(first).toMatchObject({
        child_ages: [4],
        room_id: '9',
        bed_ids: [],
        beds: '1',
    });
    expect(second).toMatchObject({
        adults: '1',
        child_ages: [],
        room_id: '',
        bed_ids: ['3', ''],
        beds: '2',
    });
});

test('moving an add-on takes it from one line and puts it last on the other, and nothing else changes', () => {
    const addOn = (id: string) => ({
        charge_item_id: id,
        quantity: '1',
        unit_amount: '',
        service_date: '',
    });
    const first = { ...emptyLine(), add_ons: [addOn('1'), addOn('2')] };
    const second = { ...emptyLine(), add_ons: [addOn('3')] };
    const lines = [first, second];
    const moved = moveAddOn(lines, 0, 0, 1);

    expect(moved[0].add_ons.map((one) => one.charge_item_id)).toEqual(['2']);
    expect(moved[1].add_ons.map((one) => one.charge_item_id)).toEqual([
        '3',
        '1',
    ]);
    expect(lines[0].add_ons).toHaveLength(2);
    expect(moveAddOn(lines, 0, 0, 0)).toBe(lines);
    expect(moveAddOn(lines, 0, 5, 1)).toBe(lines);
});
