import { expect, test } from 'vite-plus/test';
import { emptyGuest, linkGuest } from './booking-guests';
import type { GuestLink } from './booking-guests';
import {
    draftHasContent,
    groupKey,
    guestGroups,
    profileChanged,
    fullUnitIds,
    unitsWithoutGuest,
} from './guest-rows';

type Row = App.Data.Reservations.ReservationGuestData;
type Unit = App.Data.Reservations.ReservationUnitOptionData;

const badge = (value: string) =>
    ({ value, label: value, tone: 'neutral' }) as never;

const guest: GuestLink = {
    id: 7,
    fullName: 'Ahmad Faiz',
    phone: '+60123456789',
    email: null,
    isVip: false,
    isBlacklisted: false,
    profile: {
        nationalityCode: 'MY',
        dateOfBirth: null,
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

function unit(id: number, isBed = false, capacity = isBed ? 1 : 2): Unit {
    return {
        id,
        roomType: 'Dorm',
        isBed,
        capacity,
        assigned: null,
        label: `Unit ${id}`,
        status: badge('confirmed'),
        arrival: '2026-10-12',
        departure: '2026-10-14',
    };
}

function row(
    unitId: number | null,
    name: string | null,
    status = 'confirmed',
): Row {
    return {
        guestId: null,
        name,
        phone: null,
        unitId,
        unitLabel: unitId === null ? null : `Unit ${unitId}`,
        unitStatus: unitId === null ? null : badge(status),
        roles: [],
        cardUnitGuestId: null,
        billing: null,
        canBill: false,
        guest: null,
    };
}

test('a saved row is changed only by its profile fields', () => {
    const base = linkGuest(emptyGuest(), guest);

    expect(
        profileChanged({ ...base, stays_in: '9', update_profile: true }, base),
    ).toBe(false);
    expect(profileChanged({ ...base, city: 'Penang' }, base)).toBe(true);
    expect(profileChanged({ ...base, full_name: 'Ahmad F.' }, base)).toBe(true);
});

test('a draft has content once something is typed or picked', () => {
    expect(draftHasContent(emptyGuest('3'))).toBe(false);
    expect(draftHasContent({ ...emptyGuest(), full_name: ' Siti ' })).toBe(
        true,
    );
    expect(draftHasContent(linkGuest(emptyGuest(), guest))).toBe(true);
});

test('a unit is full once its saved guests and the other drafts reach its capacity', () => {
    const units = [unit(1, true), unit(2, true), unit(3), unit(4)];
    const rows = [row(1, 'Ahmad'), row(2, null), row(3, 'Siti'), row(4, 'Ali')];
    const mine = { ...emptyGuest('2') };
    const other = { ...emptyGuest('2') };
    const roommate = { ...emptyGuest('3') };

    expect([
        ...fullUnitIds(rows, [mine, other, roommate], units, mine.key),
    ]).toEqual([1, 2, 3]);
    expect([...fullUnitIds(rows, [mine], units, mine.key)]).toEqual([1]);
});

test('the footer counts open units with no guest and no draft picking them', () => {
    const rows = [
        row(1, null),
        row(2, null),
        row(3, null, 'cancelled'),
        row(4, 'Ahmad'),
    ];

    expect(unitsWithoutGuest(rows, [])).toBe(2);
    expect(unitsWithoutGuest(rows, [emptyGuest('1')])).toBe(1);
});

test('the rail lists Not staying first, then every unit with its guest count', () => {
    const units = [unit(1), unit(2)];
    const rows = [
        row(null, 'Chloe'),
        row(1, 'Ahmad'),
        row(1, 'Siti'),
        row(2, null),
    ];
    const groups = guestGroups(rows, units);

    expect(groups.map((group) => [group.key, group.count])).toEqual([
        ['none', 1],
        ['1', 2],
        ['2', 0],
    ]);
    expect(rows.map(groupKey)).toEqual(['none', '1', '1', '2']);
});

test('there is no Not staying entry when the booker stays', () => {
    const groups = guestGroups([row(1, 'Chloe')], [unit(1)]);

    expect(groups.map((group) => group.key)).toEqual(['1']);
});
