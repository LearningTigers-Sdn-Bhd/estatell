type Booking = App.Data.Portal.PortalBookingData;
type Guest = App.Data.Portal.PortalGuestData;

/** The rail key of the booker who stays in no unit. */
export const NOT_STAYING_KEY = 'none';

export type GuestRow = {
    key: string;
    /** The unit's rail key, or `none` for the booker who stays in no unit. */
    group: string;
    unit: string | null;
    guest: Guest;
};

export type GuestGroup = { key: string; label: string; count: number };

/**
 * The name of each unit: its room type, with a number when two units share one ("Garden Room 1", "Garden Room 2"),
 * because the portal shows no room numbers.
 */
export function unitLabels(booking: Booking): Map<number, string> {
    const totals = new Map<string, number>();
    const seen = new Map<string, number>();
    const labels = new Map<number, string>();

    for (const unit of booking.units) {
        totals.set(unit.roomType, (totals.get(unit.roomType) ?? 0) + 1);
    }

    for (const unit of booking.units) {
        const position = (seen.get(unit.roomType) ?? 0) + 1;

        seen.set(unit.roomType, position);
        labels.set(
            unit.id,
            (totals.get(unit.roomType) ?? 0) > 1
                ? `${unit.roomType} ${position}`
                : unit.roomType,
        );
    }

    return labels;
}

/**
 * One row per guest, in unit order, with the unit each guest stays in. A booker who stays in no unit comes last, with
 * no unit. Display only: the server sends the units' guest ids and the guests.
 */
export function guestRows(booking: Booking): GuestRow[] {
    const byId = new Map(booking.guests.map((guest) => [guest.guestId, guest]));
    const labels = unitLabels(booking);
    const placed = new Set<number>();
    const rows: GuestRow[] = [];

    for (const unit of booking.units) {
        for (const guestId of unit.guestIds) {
            const guest = byId.get(guestId);

            if (guest !== undefined) {
                placed.add(guestId);
                rows.push({
                    key: `${unit.id}-${guestId}`,
                    group: String(unit.id),
                    unit: labels.get(unit.id) ?? unit.roomType,
                    guest,
                });
            }
        }
    }

    for (const guest of booking.guests) {
        if (!placed.has(guest.guestId)) {
            rows.push({
                key: `none-${guest.guestId}`,
                group: NOT_STAYING_KEY,
                unit: null,
                guest,
            });
        }
    }

    return rows;
}

/**
 * The rail entries of the Grouped view: one per unit with its guest count, then Not staying when the booker stays in
 * no unit.
 */
export function guestGroups(booking: Booking): GuestGroup[] {
    const rows = guestRows(booking);
    const labels = unitLabels(booking);
    const count = (key: string) =>
        rows.filter((row) => row.group === key).length;
    const groups = booking.units.map((unit) => ({
        key: String(unit.id),
        label: labels.get(unit.id) ?? unit.roomType,
        count: count(String(unit.id)),
    }));
    const notStaying = count(NOT_STAYING_KEY);

    return notStaying > 0
        ? [
              ...groups,
              {
                  key: NOT_STAYING_KEY,
                  label: 'Not staying',
                  count: notStaying,
              },
          ]
        : groups;
}
