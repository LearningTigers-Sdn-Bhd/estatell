import { expect, test } from 'vite-plus/test';
import {
    clampStaysIn,
    emptyGuest,
    guestsPayload,
    guestLabel,
    hasDetails,
    linkGuest,
    firstUnitWithSpace,
    fullUnits,
    unitsWithoutGuest,
    unlinkGuest,
} from './booking-guests';
import type { GuestLink } from './booking-guests';
import { emptyLine, unitOptions } from './booking-lines';

const guest: GuestLink = {
    id: 7,
    fullName: 'Ahmad Faiz',
    phone: '+60123456789',
    email: 'ahmad@example.com',
    isVip: true,
    isBlacklisted: false,
    profile: {
        nationalityCode: 'MY',
        dateOfBirth: '1990-05-04',
        gender: null,
        preferredLocale: null,
        addressLine1: null,
        addressLine2: null,
        city: 'Ipoh',
        state: null,
        postalCode: null,
        countryCode: null,
        blacklistReason: null,
    },
};

const room = {
    id: 1,
    name: 'Deluxe King',
    isBed: false,
    maxAdults: 2,
    maxChildren: 2,
};
const dorm = { id: 2, name: 'Dorm', isBed: true, maxAdults: 1, maxChildren: 0 };

test('picking a guest links the tab and fills every field, and nothing is left from before', () => {
    const typed = {
        ...emptyGuest('1'),
        full_name: 'Ahm',
        city: 'Old',
        update_profile: true,
    };
    const linked = linkGuest(typed, guest);

    expect(linked.guest).toBe(guest);
    expect(linked).toMatchObject({
        full_name: 'Ahmad Faiz',
        phone: '+60123456789',
        email: 'ahmad@example.com',
        nationality_code: 'MY',
        date_of_birth: '1990-05-04',
        gender: '',
        city: 'Ipoh',
        update_profile: false,
        stays_in: '1',
        key: typed.key,
    });
});

test('changing the guest keeps what is typed and unlinks', () => {
    const unlinked = unlinkGuest({
        ...linkGuest(emptyGuest(), guest),
        update_profile: true,
    });

    expect(unlinked.guest).toBeNull();
    expect(unlinked.update_profile).toBe(false);
    expect(unlinked.full_name).toBe('Ahmad Faiz');
});

test('more details open by themselves only when one is filled', () => {
    expect(hasDetails(emptyGuest())).toBe(false);
    expect(emptyGuest('', 'MY').nationality_code).toBe('MY');
    expect(hasDetails(linkGuest(emptyGuest(), guest))).toBe(true);
});

test('a tab is labelled by name, then Booker or Guest N', () => {
    expect(guestLabel(emptyGuest(), 0)).toBe('Booker');
    expect(guestLabel(emptyGuest(), 2)).toBe('Guest 3');
    expect(guestLabel({ ...emptyGuest(), full_name: ' Aina ' }, 1)).toBe(
        'Aina',
    );
});

test('units are listed in the order the lines add them, numbered for each room type', () => {
    const lines = [
        { ...emptyLine(), room_type_id: '1' },
        { ...emptyLine(), room_type_id: '2', beds: '2' },
        { ...emptyLine(), room_type_id: '1' },
        emptyLine(),
    ];

    expect(unitOptions(lines, [room, dorm])).toEqual([
        { value: '0', label: 'Deluxe King #1', isBed: false, capacity: 2 },
        { value: '1', label: 'Dorm · Bed 1', isBed: true, capacity: 1 },
        { value: '2', label: 'Dorm · Bed 2', isBed: true, capacity: 1 },
        { value: '3', label: 'Deluxe King #2', isBed: false, capacity: 2 },
    ]);
});

test('a stays-in past the units or over a unit capacity is cleared, the earlier tabs keep theirs', () => {
    const options = unitOptions(
        [
            { ...emptyLine(), room_type_id: '1', adults: '2' },
            { ...emptyLine(), room_type_id: '2', beds: '1' },
        ],
        [room, dorm],
    );
    const tabs = [emptyGuest('0'), emptyGuest('1'), emptyGuest('')];

    expect(clampStaysIn(tabs, options)).toBe(tabs);
    expect(
        clampStaysIn(tabs, options.slice(0, 1)).map((tab) => tab.stays_in),
    ).toEqual(['0', '', '']);

    const crowded = [emptyGuest('0'), emptyGuest('0'), emptyGuest('0')];

    expect(clampStaysIn(crowded, options).map((tab) => tab.stays_in)).toEqual([
        '0',
        '0',
        '',
    ]);
});

test('a room holds its adults and children, a bed one guest', () => {
    const options = unitOptions(
        [
            {
                ...emptyLine(),
                room_type_id: '1',
                adults: '1',
                child_ages: ['5'],
            },
            { ...emptyLine(), room_type_id: '2', beds: '1' },
        ],
        [room, dorm],
    );

    expect(options.map((option) => option.capacity)).toEqual([2, 1]);
});

test('a full unit is disabled for another tab, and a new tab starts in the first unit with space', () => {
    const options = unitOptions(
        [
            { ...emptyLine(), room_type_id: '1', adults: '2' },
            { ...emptyLine(), room_type_id: '2', beds: '1' },
        ],
        [room, dorm],
    );
    const tabs = [emptyGuest('0'), emptyGuest('0'), emptyGuest('')];

    expect([...fullUnits(tabs, options, 2)]).toEqual(['0']);
    expect([...fullUnits(tabs, options, 1)]).toEqual([]);
    expect(firstUnitWithSpace(tabs, options)).toBe('1');
    expect(firstUnitWithSpace([...tabs, emptyGuest('1')], options)).toBe('');
});

test('a linked guest posts only its id, and a new or updated guest posts what was typed', () => {
    const linked = linkGuest(emptyGuest('0'), guest);
    const updating = { ...linked, update_profile: true, city: 'Melaka' };
    const added = { ...emptyGuest('1'), full_name: 'Nur Aina', phone: '012' };
    const [first, second, third] = guestsPayload([linked, updating, added]);

    expect(first).toEqual({
        guest_id: 7,
        update_profile: false,
        stays_in: '0',
    });
    expect(second).toMatchObject({
        guest_id: 7,
        update_profile: true,
        city: 'Melaka',
        full_name: 'Ahmad Faiz',
    });
    expect(second).not.toHaveProperty('key');
    expect(second).not.toHaveProperty('guest');
    expect(third).toMatchObject({
        guest_id: '',
        full_name: 'Nur Aina',
        phone: '012',
        stays_in: '1',
    });
});

test('the footer counts the units that have no guest, once for each unit', () => {
    const guests = [
        { ...emptyGuest('0') },
        { ...emptyGuest('0') },
        { ...emptyGuest('2') },
        { ...emptyGuest('') },
    ];

    expect(unitsWithoutGuest(guests, 4)).toBe(2);
    expect(unitsWithoutGuest(guests, 2)).toBe(0);
    expect(unitsWithoutGuest([emptyGuest()], 0)).toBe(0);
});
