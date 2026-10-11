import { expect, test } from 'vite-plus/test';
import { emptyGuest, linkGuest } from './booking-guests';
import type { GuestLink } from './booking-guests';
import {
    draftHasContent,
    profileChanged,
    takenBedUnits,
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

function unit(id: number, isBed = false): Unit {
    return {
        id,
        roomType: 'Dorm',
        isBed,
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

test('a bed unit with a saved guest or another draft is taken', () => {
    const units = [unit(1, true), unit(2, true), unit(3)];
    const rows = [row(1, 'Ahmad'), row(2, null), row(3, 'Siti')];
    const mine = { ...emptyGuest('2') };
    const other = { ...emptyGuest('2') };

    expect([...takenBedUnits(rows, [mine, other], units, mine.key)]).toEqual([
        1, 2,
    ]);
    expect([...takenBedUnits(rows, [mine], units, mine.key)]).toEqual([1]);
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
