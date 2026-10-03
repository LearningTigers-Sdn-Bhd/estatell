import { EMPTY_GUEST_DETAILS } from '@/components/pms/guests/guest-details-fields';
import type { GuestDetails } from '@/components/pms/guests/guest-details-fields';
import type { UnitOption } from '@/components/pms/reservations/booking-lines';

/** A guest picked from the search or from a duplicate match. Both carry the profile a tab fills from. */
export type GuestLink =
    | App.Data.Guests.GuestOptionData
    | App.Data.Guests.GuestMatchData;

/**
 * One guest tab of the booking form. The first tab is the booker. `guest` links an existing guest; without it a new guest
 * is created on save. `stays_in` is the unit the guest stays in, as an index in the order the lines add units, or empty.
 */
export type BookingGuest = GuestDetails & {
    /** Client only: keeps the tab when another tab is removed. */
    key: string;
    guest: GuestLink | null;
    full_name: string;
    phone: string;
    email: string;
    update_profile: boolean;
    stays_in: string;
};

let guestCount = 0;

export function emptyGuest(staysIn = ''): BookingGuest {
    guestCount += 1;

    return {
        ...EMPTY_GUEST_DETAILS,
        key: `guest-${guestCount}`,
        guest: null,
        full_name: '',
        phone: '',
        email: '',
        update_profile: false,
        stays_in: staysIn,
    };
}

/** Links the tab to an existing guest and fills every field from that guest. */
export function linkGuest(tab: BookingGuest, guest: GuestLink): BookingGuest {
    const { profile } = guest;

    return {
        ...tab,
        guest,
        update_profile: false,
        full_name: guest.fullName,
        phone: guest.phone ?? '',
        email: guest.email ?? '',
        nationality_code: profile.nationalityCode ?? '',
        date_of_birth: profile.dateOfBirth ?? '',
        gender: profile.gender ?? '',
        preferred_locale: profile.preferredLocale ?? '',
        address_line1: profile.addressLine1 ?? '',
        address_line2: profile.addressLine2 ?? '',
        city: profile.city ?? '',
        state: profile.state ?? '',
        postal_code: profile.postalCode ?? '',
        country_code: profile.countryCode ?? '',
    };
}

/** Unlinks the guest and keeps what is typed, so the tab becomes a new guest. */
export function unlinkGuest(tab: BookingGuest): BookingGuest {
    return { ...tab, guest: null, update_profile: false };
}

const DETAIL_FIELDS = Object.keys(
    EMPTY_GUEST_DETAILS,
) as (keyof GuestDetails)[];

/** Whether any of the "More guest details" is filled, so the section opens by itself. */
export function hasDetails(tab: GuestDetails): boolean {
    return DETAIL_FIELDS.some((field) => tab[field] !== '');
}

/** The row label: the guest's name, or "Booker" or "Guest N" while it is empty. */
export function guestLabel(tab: BookingGuest, index: number): string {
    const name = tab.full_name.trim();

    if (name !== '') {
        return name;
    }

    return index === 0 ? 'Booker' : `Guest ${index + 1}`;
}

/** Clears a "stays in" that no longer points at a unit, after a line was removed or its beds were reduced. */
export function clampStaysIn(
    tabs: BookingGuest[],
    unitCount: number,
): BookingGuest[] {
    const stale = tabs.some(
        (tab) => tab.stays_in !== '' && Number(tab.stays_in) >= unitCount,
    );

    return stale
        ? tabs.map((tab) =>
              tab.stays_in !== '' && Number(tab.stays_in) >= unitCount
                  ? { ...tab, stays_in: '' }
                  : tab,
          )
        : tabs;
}

/** The bed units that already have a guest on another tab, so a second guest cannot be picked for them. */
export function takenBedUnits(
    tabs: BookingGuest[],
    options: UnitOption[],
    exceptIndex: number,
): Set<string> {
    const taken = new Set<string>();

    tabs.forEach((tab, index) => {
        if (
            index !== exceptIndex &&
            tab.stays_in !== '' &&
            options[Number(tab.stays_in)]?.isBed
        ) {
            taken.add(tab.stays_in);
        }
    });

    return taken;
}

/**
 * The guest tabs as the server takes them. A linked guest sends only its id unless the profile update is on, and a new
 * guest sends what was typed.
 */
export function guestsPayload(tabs: BookingGuest[]) {
    return tabs.map((tab) => {
        const { key: _key, guest, ...fields } = tab;

        if (guest !== null && !tab.update_profile) {
            return {
                guest_id: guest.id,
                update_profile: false,
                stays_in: tab.stays_in,
            };
        }

        return {
            ...fields,
            guest_id: guest?.id ?? '',
        };
    });
}

/** How many units have no guest staying in them, for the footer of the Guests table. */
export function unitsWithoutGuest(
    guests: BookingGuest[],
    unitCount: number,
): number {
    const filled = new Set(
        guests
            .filter((guest) => guest.stays_in !== '')
            .map((guest) => guest.stays_in),
    );

    return Math.max(0, unitCount - filled.size);
}
