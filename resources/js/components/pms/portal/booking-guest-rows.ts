type Booking = App.Data.Portal.PortalBookingData;
type Guest = App.Data.Portal.PortalGuestData;

export type GuestRow = { key: string; unit: string | null; guest: Guest };

/**
 * One row per guest, in unit order, with the unit each guest stays in. A booker who stays in no unit comes last, with
 * no unit. Display only: the server sends the units' guest ids and the guests.
 */
export function guestRows(booking: Booking): GuestRow[] {
    const byId = new Map(booking.guests.map((guest) => [guest.guestId, guest]));
    const placed = new Set<number>();
    const rows: GuestRow[] = [];

    for (const unit of booking.units) {
        for (const guestId of unit.guestIds) {
            const guest = byId.get(guestId);

            if (guest !== undefined) {
                placed.add(guestId);
                rows.push({
                    key: `${unit.id}-${guestId}`,
                    unit: unit.roomType,
                    guest,
                });
            }
        }
    }

    for (const guest of booking.guests) {
        if (!placed.has(guest.guestId)) {
            rows.push({ key: `none-${guest.guestId}`, unit: null, guest });
        }
    }

    return rows;
}
