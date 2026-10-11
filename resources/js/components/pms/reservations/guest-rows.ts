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

/** Bed units that already hold a saved guest or another draft, so a second guest cannot be picked for them. */
export function takenBedUnits(
    rows: Row[],
    drafts: BookingGuest[],
    units: Unit[],
    exceptKey: string,
): Set<number> {
    const beds = new Set(
        units.filter((unit) => unit.isBed).map((unit) => unit.id),
    );
    const taken = new Set<number>();

    rows.forEach((row) => {
        if (row.name !== null && row.unitId !== null && beds.has(row.unitId)) {
            taken.add(row.unitId);
        }
    });

    drafts.forEach((draft) => {
        const unitId = Number(draft.stays_in);

        if (
            draft.key !== exceptKey &&
            draft.stays_in !== '' &&
            beds.has(unitId)
        ) {
            taken.add(unitId);
        }
    });

    return taken;
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
