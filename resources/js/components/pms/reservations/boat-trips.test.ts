import { describe, expect, test } from 'vite-plus/test';
import {
    boatOptions,
    boatTripPayload,
    choiceOf,
    departuresFor,
    isScheduled,
    isTimed,
    NOT_SET,
    overSeatsMessage,
    pickedTime,
    type BoatDeparture,
} from './boat-trips';
import { bookingPayload, emptyLine, type BookingForm } from './booking-lines';

const inBoat: BoatDeparture = {
    id: 12,
    direction: 'in',
    leavesAt: '09:00',
    propertyTime: '09:45',
    pickupPoint: 'Kuala Besut jetty',
    seats: 20,
};
const noLimit: BoatDeparture = {
    id: 13,
    direction: 'in',
    leavesAt: '13:00',
    propertyTime: '13:45',
    pickupPoint: null,
    seats: null,
};
const outBoat: BoatDeparture = {
    id: 14,
    direction: 'out',
    leavesAt: '12:00',
    propertyTime: '12:00',
    pickupPoint: 'Resort jetty',
    seats: 20,
};
const departures = [inBoat, noLimit, outBoat];

describe('choices', () => {
    test('a departure is scheduled, charter and own boat are timed, and not set is neither', () => {
        expect(isScheduled({ value: '12', note: '' })).toBe(true);
        expect(isScheduled({ value: 'charter', note: '' })).toBe(false);
        expect(isScheduled(NOT_SET)).toBe(false);
        expect(isTimed({ value: 'own', note: '' })).toBe(true);
        expect(isTimed({ value: '12', note: '' })).toBe(false);
    });

    test('a note is kept only for a charter or own boat', () => {
        expect(choiceOf('charter', 'Private transfer')).toEqual({
            value: 'charter',
            note: 'Private transfer',
        });
        expect(choiceOf('12', 'Private transfer')).toEqual({
            value: '12',
            note: '',
        });
    });

    test('departuresFor keeps one direction in its order', () => {
        expect(departuresFor(departures, 'in').map((d) => d.id)).toEqual([
            12, 13,
        ]);
        expect(departuresFor(departures, 'out').map((d) => d.id)).toEqual([14]);
    });
});

describe('the time a pick sets', () => {
    test('a scheduled departure sets its property-side time', () => {
        expect(pickedTime({ value: '12', note: '' }, departures)).toBe('09:45');
        expect(pickedTime({ value: '14', note: '' }, departures)).toBe('12:00');
    });

    test('charter, own boat and not set set nothing', () => {
        expect(
            pickedTime({ value: 'charter', note: '' }, departures),
        ).toBeNull();
        expect(pickedTime(NOT_SET, departures)).toBeNull();
    });
});

describe('the options and the seats', () => {
    test('a departure with a limit shows its seats once they load', () => {
        const options = boatOptions([inBoat, noLimit], { '12': 14 }, true);

        expect(options.map((option) => option.label)).toEqual([
            'Not set',
            '09:00 · 14 / 20 seats',
            '13:00',
            'Charter',
            'Own boat',
        ]);
    });

    test('a departure shows only its time while the seats load', () => {
        expect(boatOptions([inBoat], {}, false)[1].label).toBe('09:00');
    });

    test('a full boat warns with the seats of the server plus this booking', () => {
        expect(overSeatsMessage(inBoat, { '12': 14 }, 7)).toBe(
            'This boat is over its 20 seats.',
        );
        expect(overSeatsMessage(inBoat, { '12': 14 }, 6)).toBeNull();
    });

    test('a departure with no limit never warns', () => {
        expect(overSeatsMessage(noLimit, { '13': 99 }, 10)).toBeNull();
    });
});

describe('the request value of a pick', () => {
    test('not set sends null', () => {
        expect(boatTripPayload(NOT_SET, '15:00')).toBeNull();
    });

    test('a scheduled departure sends no time and no note', () => {
        expect(
            boatTripPayload({ value: '12', note: 'ignored' }, '09:45'),
        ).toEqual({
            mode: 'scheduled',
            boat_departure_id: 12,
            time: null,
            note: null,
        });
    });

    test('a charter sends the stay row time and the trimmed note', () => {
        expect(
            boatTripPayload(
                { value: 'charter', note: '  Private transfer ' },
                '10:30',
            ),
        ).toEqual({
            mode: 'charter',
            boat_departure_id: null,
            time: '10:30',
            note: 'Private transfer',
        });
    });

    test('an own boat sends its mode and an empty note as null', () => {
        expect(
            boatTripPayload({ value: 'own', note: '   ' }, '11:30')?.mode,
        ).toBe('own');
        expect(
            boatTripPayload({ value: 'own', note: '   ' }, '11:30')?.note,
        ).toBeNull();
    });
});

describe('the booking payload', () => {
    const base: BookingForm = {
        stay_type: 'overnight',
        start_time: '',
        source: 'phone',
        external_reference: '',
        arrival_date: '2026-10-20',
        departure_date: '2026-10-22',
        arrival_time: '15:00',
        departure_time: '11:00',
        group_name: '',
        company_id: '',
        credit_override_reason: '',
        billing_mode: 'master_pays',
        notes: '',
        guests: [],
        lines: [emptyLine()],
        boat_in: NOT_SET,
        boat_out: NOT_SET,
    };
    const roomTypes = [
        { id: 1, name: 'Deluxe', isBed: false, maxAdults: 2, maxChildren: 2 },
    ];

    test('an overnight booking sends each trip with its own time', () => {
        const payload = bookingPayload(
            {
                ...base,
                arrival_time: '09:45',
                boat_in: { value: '12', note: '' },
                boat_out: { value: 'charter', note: 'Transfer' },
                departure_time: '10:15',
            },
            roomTypes,
        );

        expect(payload.boat_in).toEqual({
            mode: 'scheduled',
            boat_departure_id: 12,
            time: null,
            note: null,
        });
        expect(payload.boat_out).toEqual({
            mode: 'charter',
            boat_departure_id: null,
            time: '10:15',
            note: 'Transfer',
        });
    });

    test('a day use booking sends no trips', () => {
        const payload = bookingPayload(
            {
                ...base,
                stay_type: 'day_use',
                boat_in: { value: '12', note: '' },
            },
            roomTypes,
        );

        expect(payload.boat_in).toBeNull();
        expect(payload.boat_out).toBeNull();
    });
});
