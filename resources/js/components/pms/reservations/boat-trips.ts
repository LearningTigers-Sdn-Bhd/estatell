import type { EnumOption } from '@/components/pms/enum-select';

export type BoatDeparture = App.Data.Boats.BoatDepartureOptionData;

export type BoatDirection = 'in' | 'out';

/**
 * One Boat in or Boat out pick. `value` is '' for Not set, 'charter' or 'own', or the id of an available departure as
 * text. `note` is kept only for a charter or own boat.
 */
export type BoatChoice = { value: string; note: string };

export type BoatTripPayload = {
    mode: 'scheduled' | 'charter' | 'own';
    boat_departure_id: number | null;
    time: string | null;
    note: string | null;
};

export const NOT_SET: BoatChoice = { value: '', note: '' };

/** A pick made in the select, with the note kept only for a charter or own boat. */
export function choiceOf(value: string, note: string): BoatChoice {
    return { value, note: isTimed({ value, note }) ? note : '' };
}

/** A scheduled departure is picked: the time is locked and the server sets it. */
export function isScheduled(choice: BoatChoice): boolean {
    return (
        choice.value !== '' &&
        choice.value !== 'charter' &&
        choice.value !== 'own'
    );
}

/** A charter or own boat: the Stay row time is the trip time, and a note shows. */
export function isTimed(choice: BoatChoice): boolean {
    return choice.value === 'charter' || choice.value === 'own';
}

/** The departures of one direction, in the order the server sent them (leave time). */
export function departuresFor(
    departures: BoatDeparture[],
    direction: BoatDirection,
): BoatDeparture[] {
    return departures.filter((departure) => departure.direction === direction);
}

/**
 * The option labels of a Boat in or Boat out select: "09:00 · 14 / 20 seats" for a departure with a limit, "09:00"
 * without one or while the seats load. Charter and Own boat come last.
 */
export function boatOptions(
    departures: BoatDeparture[],
    seats: Record<string, number>,
    loaded: boolean,
): EnumOption[] {
    return [
        { value: '', label: 'Not set' },
        ...departures.map((departure) => ({
            value: String(departure.id),
            label:
                departure.seats === null || !loaded
                    ? departure.leavesAt
                    : `${departure.leavesAt} · ${seats[String(departure.id)] ?? 0} / ${departure.seats} seats`,
        })),
        { value: 'charter', label: 'Charter' },
        { value: 'own', label: 'Own boat' },
    ];
}

/**
 * The warning for a departure the booking would fill past its seats. It counts the seats taken on the server plus this
 * booking's guests, in the browser only: the server never refuses a full boat.
 */
export function overSeatsMessage(
    departure: BoatDeparture,
    seats: Record<string, number>,
    bookingSeats: number,
): string | null {
    if (departure.seats === null) {
        return null;
    }

    const taken = (seats[String(departure.id)] ?? 0) + bookingSeats;

    return taken > departure.seats
        ? `This boat is over its ${departure.seats} seats.`
        : null;
}

/**
 * The time a pick puts on the Stay row: the departure's property-side time, for a scheduled pick only. A charter or own
 * boat keeps the time the staff typed, so it returns null.
 */
export function pickedTime(
    choice: BoatChoice,
    departures: BoatDeparture[],
): string | null {
    if (!isScheduled(choice)) {
        return null;
    }

    return (
        departures.find((departure) => String(departure.id) === choice.value)
            ?.propertyTime ?? null
    );
}

/**
 * The `boat_in` or `boat_out` of the booking request. Not set sends null. A scheduled trip sends no time, because the
 * server takes it from the departure. A charter or own boat sends the Stay row time, the same field the stay uses.
 */
export function boatTripPayload(
    choice: BoatChoice,
    stayTime: string,
): BoatTripPayload | null {
    if (choice.value === '') {
        return null;
    }

    const note = choice.note.trim() === '' ? null : choice.note.trim();

    if (isScheduled(choice)) {
        return {
            mode: 'scheduled',
            boat_departure_id: Number(choice.value),
            time: null,
            note: null,
        };
    }

    return {
        mode: choice.value === 'own' ? 'own' : 'charter',
        boat_departure_id: null,
        time: stayTime,
        note,
    };
}

/** The choice a unit's trip shows in a select: its departure, or Charter or Own boat with its note. Not set when there is none. */
export function choiceFromTrip(
    trip: App.Data.Boats.UnitBoatTripData | null,
): BoatChoice {
    if (trip === null) {
        return NOT_SET;
    }

    if (trip.mode === 'scheduled') {
        return choiceOf(String(trip.boatDepartureId ?? ''), '');
    }

    return choiceOf(trip.mode, trip.note ?? '');
}

/** One unit of the Set boats Dialog: the choices of the directions the staff may change, and each unit's Stay row times. */
export type BoatSetRow = {
    unitId: number;
    in: BoatChoice | null;
    out: BoatChoice | null;
    stayIn: string;
    stayOut: string;
};

export type BoatSetUnitPayload = { unit_id: number } & {
    in?: BoatTripPayload | null;
    out?: BoatTripPayload | null;
};

/**
 * The `units` of the Set boats request. A direction that is read only (null choice) is left out, so the server keeps
 * it. Not set sends null, which clears the trip. Each other direction sends its trip.
 */
export function boatSetPayload(rows: BoatSetRow[]): BoatSetUnitPayload[] {
    return rows.map((row) => {
        const payload: BoatSetUnitPayload = { unit_id: row.unitId };

        if (row.in !== null) {
            payload.in = boatTripPayload(row.in, row.stayIn);
        }

        if (row.out !== null) {
            payload.out = boatTripPayload(row.out, row.stayOut);
        }

        return payload;
    });
}
