import { EMPTY_GUEST_DETAILS } from '@/components/pms/guests/guest-details-fields';
import type { BookingGuest } from '@/components/pms/reservations/booking-guests';

type Row = App.Data.Reservations.ReservationGuestData;
type Unit = App.Data.Reservations.ReservationUnitOptionData;

/** Units that take a guest: the same statuses `AttachGuest` accepts. */
export const ATTACHABLE_STATUSES = ['confirmed', 'checked_in', 'checked_out'];

/** Units that are still in the stay. A guest is expected on these. */
export const OPEN_STATUSES = ['confirmed', 'checked_in'];

/** The most draft rows at one time, like the booking form's guest limit. */
export const MAX_DRAFTS = 20;

const PROFILE_FIELDS = [
    'full_name',
    'phone',
    'email',
    ...Object.keys(EMPTY_GUEST_DETAILS),
] as const;

/** Whether the typed profile of a saved row differs from what was loaded. */
export function profileChanged(
    current: BookingGuest,
    base: BookingGuest,
): boolean {
    return PROFILE_FIELDS.some(
        (field) =>
            current[field as keyof BookingGuest] !==
            base[field as keyof BookingGuest],
    );
}

/** The profile fields of a row, as the update route takes them. */
export function profilePayload(values: BookingGuest) {
    return Object.fromEntries(
        PROFILE_FIELDS.map((field) => [
            field,
            values[field as keyof BookingGuest],
        ]),
    );
}

/** Whether a draft row has anything typed or picked, so leaving the page asks first. */
export function draftHasContent(draft: BookingGuest): boolean {
    return (
        draft.guest !== null ||
        draft.full_name.trim() !== '' ||
        draft.phone.trim() !== '' ||
        draft.email.trim() !== ''
    );
}

/**
 * Units that are full with their saved guests and the other drafts (a bed holds one, a room its adults and children),
 * so another guest cannot be picked for them.
 */
export function fullUnitIds(
    rows: Row[],
    drafts: BookingGuest[],
    units: Unit[],
    exceptKey: string,
): Set<number> {
    const counts = new Map<number, number>();
    const add = (unitId: number) =>
        counts.set(unitId, (counts.get(unitId) ?? 0) + 1);

    rows.forEach((row) => {
        if (row.name !== null && row.unitId !== null) {
            add(row.unitId);
        }
    });

    drafts.forEach((draft) => {
        if (draft.key !== exceptKey && draft.stays_in !== '') {
            add(Number(draft.stays_in));
        }
    });

    return new Set(
        units
            .filter((unit) => (counts.get(unit.id) ?? 0) >= unit.capacity)
            .map((unit) => unit.id),
    );
}

/** Open units with no saved guest and no draft that picks them, for the footer count. */
export function unitsWithoutGuest(rows: Row[], drafts: BookingGuest[]): number {
    const picked = new Set(
        drafts
            .filter((draft) => draft.stays_in !== '')
            .map((draft) => Number(draft.stays_in)),
    );

    return rows.filter(
        (row) =>
            row.name === null &&
            row.unitId !== null &&
            row.unitStatus !== null &&
            OPEN_STATUSES.includes(row.unitStatus.value) &&
            !picked.has(row.unitId),
    ).length;
}

/** One entry of the Grouped view rail: a unit, or "Not staying" for a booker who is not on a unit. */
export type GuestGroup = {
    key: string;
    unitId: number | null;
    label: string;
    status: Unit['status'] | null;
    /** Guests saved on the unit. */
    count: number;
};

export const NOT_STAYING_KEY = 'none';

/** The key of the group a saved row belongs to. */
export function groupKey(row: Row): string {
    return row.unitId === null ? NOT_STAYING_KEY : String(row.unitId);
}

/** The rail entries: "Not staying" first when the booker does not stay, then every unit in unit order. */
export function guestGroups(rows: Row[], units: Unit[]): GuestGroup[] {
    const groups: GuestGroup[] = [];

    if (rows.some((row) => row.unitId === null && row.name !== null)) {
        groups.push({
            key: NOT_STAYING_KEY,
            unitId: null,
            label: 'Not staying',
            status: null,
            count: 1,
        });
    }

    units.forEach((unit) => {
        groups.push({
            key: String(unit.id),
            unitId: unit.id,
            label: unit.label,
            status: unit.status,
            count: rows.filter(
                (row) => row.unitId === unit.id && row.name !== null,
            ).length,
        });
    });

    return groups;
}
