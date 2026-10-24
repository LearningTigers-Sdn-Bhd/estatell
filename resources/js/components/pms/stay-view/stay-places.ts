type Room = App.Data.StayView.RoomRowData;
type Stay = App.Data.StayView.StayData;

export type StayPlace = {
    label: string;
    firstNight: string;
    lastNight: string;
};

/** The name of a row for a person: a room with its type, or a bed with its room. */
export function placeLabel(room: Room): string {
    return room.bedId === null
        ? `${room.label} ${room.roomTypeName}`
        : `Bed ${room.label} · ${room.roomNumber}`;
}

/**
 * Where the stay sleeps, night by night: one place for each segment, in date order. A segment whose row is not on
 * screen (hidden by a filter, or archived) still lists, as "Unknown room".
 */
export function placesOf(stay: Stay, rooms: Room[]): StayPlace[] {
    const byKey = new Map(rooms.map((room) => [room.key, room]));

    return [...stay.segments]
        .sort((a, b) => a.firstNight.localeCompare(b.firstNight))
        .map((segment) => {
            const room = byKey.get(segment.rowKey);

            return {
                label: room ? placeLabel(room) : 'Unknown room',
                firstNight: segment.firstNight,
                lastNight: segment.lastNight,
            };
        });
}

/** How many nights lie between arrival and departure. */
export function nightCount(arrival: string, departure: string): number {
    const [fromYear, fromMonth, fromDay] = arrival.split('-').map(Number);
    const [toYear, toMonth, toDay] = departure.split('-').map(Number);

    return Math.round(
        (Date.UTC(toYear, toMonth - 1, toDay) -
            Date.UTC(fromYear, fromMonth - 1, fromDay)) /
            86_400_000,
    );
}

/**
 * The units of a group booking that are loaded, the given one first among equals in arrival order. A stay that is not
 * part of a group has none. Units outside the window are not loaded, so the list can be shorter than the booking.
 */
export function groupMates(stays: Stay[], stay: Stay): Stay[] {
    if (stay.groupName === null) {
        return [];
    }

    return stays
        .filter((candidate) => candidate.reservationId === stay.reservationId)
        .sort(
            (a, b) =>
                a.arrivalDate.localeCompare(b.arrivalDate) ||
                a.unitId - b.unitId,
        );
}

/** A date as a person reads it on the grid: "Fri 23 Oct". */
export function dayLabel(date: string): string {
    return new Intl.DateTimeFormat(undefined, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        timeZone: 'UTC',
    }).format(new Date(`${date}T00:00:00Z`));
}

/** The date after the given one. A stay's last night plus one is its departure date. */
export function dayAfter(date: string): string {
    const next = new Date(`${date}T00:00:00Z`);

    next.setUTCDate(next.getUTCDate() + 1);

    return next.toISOString().slice(0, 10);
}
