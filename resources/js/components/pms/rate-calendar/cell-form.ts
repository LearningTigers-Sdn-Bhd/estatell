type Cell = App.Data.Rates.RateCalendarCellData;

/** What the date editor shows and changes. Numbers are text, so a half-typed value is kept. */
export type CellFormValues = {
    amount: string;
    /** The price for a number of adults, as text, keyed by the adult count. */
    occupancy: Record<string, string>;
    stopSell: boolean;
    closedToArrival: boolean;
    closedToDeparture: boolean;
    minStay: string;
    maxStay: string;
};

/** Where an edit goes: one plan, one room type and one date. */
export type CellTarget = {
    ratePlanId: number;
    roomTypeId: number;
    /** `Y-m-d`. */
    date: string;
    /** Monday is 1 and Sunday is 7. */
    isoWeekday: number;
};

/** The request the date editor sends to `rates.calendar.update`. */
export type CellSaveRequest = {
    from: string;
    to: string;
    room_types: {
        room_type_id: number;
        days: {
            weekday: number;
            amount: string;
            occupancy: { adults: number; amount: string }[];
        }[];
    }[];
    restrictions: {
        stop_sell: boolean;
        closed_to_arrival: boolean;
        closed_to_departure: boolean;
        min_stay: number | null;
        max_stay: number | null;
    };
};

/**
 * The values of a cell. A plan with no own price (derived or package) has no price to edit, so its price fields
 * stay empty and are never sent.
 */
export function cellFormValues(
    cell: Cell | undefined,
    ownPrice: boolean,
): CellFormValues {
    return {
        amount: ownPrice && cell?.amount ? cell.amount : '',
        occupancy: ownPrice
            ? Object.fromEntries(
                  (cell?.occupancy ?? []).map((price) => [
                      String(price.adults),
                      price.amount,
                  ]),
              )
            : {},
        stopSell: cell?.stopSell ?? false,
        closedToArrival: cell?.closedToArrival ?? false,
        closedToDeparture: cell?.closedToDeparture ?? false,
        minStay: cell?.minStay == null ? '' : String(cell.minStay),
        maxStay: cell?.maxStay == null ? '' : String(cell.maxStay),
    };
}

/** The adult counts that can have their own price: 1 up to what the room type sleeps. */
export function adultCounts(maxAdults: number): number[] {
    return Array.from(
        { length: Math.max(0, maxAdults) },
        (_, index) => index + 1,
    );
}

/** A price as a fixed two-decimal text, so "180" and "180.00" count as the same price. */
function normalizeAmount(amount: string): string {
    const trimmed = amount.trim();

    return trimmed === '' || Number.isNaN(Number(trimmed))
        ? trimmed
        : Number(trimmed).toFixed(2);
}

function filledOccupancy(values: CellFormValues): [string, string][] {
    return Object.entries(values.occupancy)
        .filter(([, amount]) => amount.trim() !== '')
        .map(([adults, amount]): [string, string] => [
            adults,
            normalizeAmount(amount),
        ])
        .sort(([left], [right]) => Number(left) - Number(right));
}

/** Whether the price or a price by adults differs from the start. */
export function priceChanged(
    initial: CellFormValues,
    current: CellFormValues,
): boolean {
    return (
        normalizeAmount(initial.amount) !== normalizeAmount(current.amount) ||
        JSON.stringify(filledOccupancy(initial)) !==
            JSON.stringify(filledOccupancy(current))
    );
}

/** Whether a restriction differs from the start. */
export function restrictionsChanged(
    initial: CellFormValues,
    current: CellFormValues,
): boolean {
    return (
        initial.stopSell !== current.stopSell ||
        initial.closedToArrival !== current.closedToArrival ||
        initial.closedToDeparture !== current.closedToDeparture ||
        initial.minStay.trim() !== current.minStay.trim() ||
        initial.maxStay.trim() !== current.maxStay.trim()
    );
}

export function isCellFormDirty(
    initial: CellFormValues,
    current: CellFormValues,
): boolean {
    return (
        priceChanged(initial, current) || restrictionsChanged(initial, current)
    );
}

/**
 * A reason the values cannot be saved, or null. The server checks everything again. This only catches what it would
 * ignore quietly: a price by adults with no price, and a price that was cleared (a price cannot be removed here).
 */
export function cellFormProblem(
    initial: CellFormValues,
    current: CellFormValues,
): string | null {
    const hasAmount = current.amount.trim() !== '';

    if (!hasAmount && filledOccupancy(current).length > 0) {
        return 'Enter the price before you set prices by adults.';
    }

    if (!hasAmount && initial.amount.trim() !== '') {
        return 'Enter a price. To stop selling this date, turn on stop sell.';
    }

    return null;
}

function toStay(value: string): number | null {
    const trimmed = value.trim();

    return trimmed === '' ? null : Number(trimmed);
}

/**
 * The request for one date. The price and prices by adults are sent only when they changed and the plan has its
 * own price, because saving a price replaces that date's prices by adults. Every restriction is sent, because a
 * blank stay limit clears it.
 */
export function buildCellPayload(
    target: CellTarget,
    initial: CellFormValues,
    current: CellFormValues,
    ownPrice: boolean,
): CellSaveRequest {
    const sendPrice =
        ownPrice &&
        current.amount.trim() !== '' &&
        priceChanged(initial, current);

    return {
        from: target.date,
        to: target.date,
        room_types: [
            {
                room_type_id: target.roomTypeId,
                days: sendPrice
                    ? [
                          {
                              weekday: target.isoWeekday,
                              amount: current.amount.trim(),
                              occupancy: filledOccupancy(current).map(
                                  ([adults, amount]) => ({
                                      adults: Number(adults),
                                      amount,
                                  }),
                              ),
                          },
                      ]
                    : [],
            },
        ],
        restrictions: {
            stop_sell: current.stopSell,
            closed_to_arrival: current.closedToArrival,
            closed_to_departure: current.closedToDeparture,
            min_stay: toStay(current.minStay),
            max_stay: toStay(current.maxStay),
        },
    };
}
