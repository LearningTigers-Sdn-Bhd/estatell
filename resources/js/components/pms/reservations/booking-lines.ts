import type { BookingGuest } from '@/components/pms/reservations/booking-guests';
import { guestsPayload } from '@/components/pms/reservations/booking-guests';

/** One booking line of the form: a room type and rate plan, the party, and what is picked for it. */
export type BookingAddOn = {
    charge_item_id: string;
    quantity: string;
    unit_amount: string;
    service_date: string;
};

export type BookingLine = {
    /** Client only: keeps a line's quote and open state when another line is removed. */
    key: string;
    room_type_id: string;
    rate_plan_id: string;
    adults: string;
    child_ages: string[];
    beds: string;
    discount_id: string;
    override_total: string;
    override_reason: string;
    restriction_override_reason: string;
    add_ons: BookingAddOn[];
    room_id: string;
    bed_ids: string[];
};

export type BookingForm = {
    source: string;
    external_reference: string;
    arrival_date: string;
    departure_date: string;
    arrival_time: string;
    departure_time: string;
    group_name: string;
    company_id: string;
    credit_override_reason: string;
    billing_mode: string;
    notes: string;
    guests: BookingGuest[];
    lines: BookingLine[];
};

type RoomTypeOption = App.Data.Reservations.RoomTypeOptionData;

let lineCount = 0;

export function emptyLine(): BookingLine {
    lineCount += 1;

    return {
        key: `line-${lineCount}`,
        room_type_id: '',
        rate_plan_id: '',
        adults: '2',
        child_ages: [],
        beds: '1',
        discount_id: '',
        override_total: '',
        override_reason: '',
        restriction_override_reason: '',
        add_ons: [],
        room_id: '',
        bed_ids: [],
    };
}

export function roomTypeOf(
    line: BookingLine,
    roomTypes: RoomTypeOption[],
): RoomTypeOption | undefined {
    return roomTypes.find((type) => String(type.id) === line.room_type_id);
}

/** How many units a line adds: one for each bed of a dorm line, otherwise one. */
export function unitsInLine(
    line: BookingLine,
    roomTypes: RoomTypeOption[],
): number {
    if (!roomTypeOf(line, roomTypes)?.isBed) {
        return 1;
    }

    const beds = Number.parseInt(line.beds, 10);

    return Number.isFinite(beds) && beds > 0 ? beds : 1;
}

export type UnitOption = { value: string; label: string; isBed: boolean };

/**
 * Every unit on the form in the order the lines add them: "Deluxe King #1", "Dorm 6-Bed · Bed 2". The value is the
 * unit index the server expects.
 */
export function unitOptions(
    lines: BookingLine[],
    roomTypes: RoomTypeOption[],
): UnitOption[] {
    const options: UnitOption[] = [];
    const seen = new Map<string, number>();

    for (const line of lines) {
        const roomType = roomTypeOf(line, roomTypes);

        if (!roomType) {
            continue;
        }

        for (let unit = 0; unit < unitsInLine(line, roomTypes); unit++) {
            const number = (seen.get(roomType.name) ?? 0) + 1;

            seen.set(roomType.name, number);
            options.push({
                value: String(options.length),
                label: roomType.isBed
                    ? `${roomType.name} · Bed ${number}`
                    : `${roomType.name} #${number}`,
                isBed: roomType.isBed,
            });
        }
    }

    return options;
}

export function totalUnits(
    lines: BookingLine[],
    roomTypes: RoomTypeOption[],
): number {
    return lines.reduce((sum, line) => sum + unitsInLine(line, roomTypes), 0);
}

/** `2026-10-20` plus days, as `Y-m-d`, without a time zone shift. */
export function addDays(date: string, days: number): string {
    const [year, month, day] = date.split('-').map(Number);
    const next = new Date(Date.UTC(year, month - 1, day + days));

    return next.toISOString().slice(0, 10);
}

/** Nights between two `Y-m-d` dates, or 0 when either is missing or the order is wrong. */
export function nightsBetween(arrival: string, departure: string): number {
    if (!arrival || !departure) {
        return 0;
    }

    const start = Date.parse(`${arrival}T00:00:00Z`);
    const end = Date.parse(`${departure}T00:00:00Z`);

    return end > start ? Math.round((end - start) / 86_400_000) : 0;
}

/** Adds decimal strings in cents, for display. The server sends every amount, so this only adds them up. */
export function sumMoney(amounts: (string | null | undefined)[]): string {
    const cents = amounts.reduce((sum, amount) => {
        const value = Number(amount);

        return Number.isFinite(value) ? sum + Math.round(value * 100) : sum;
    }, 0);

    return (cents / 100).toFixed(2);
}

/** Keeps the ages already typed and adds or drops entries so there is one for each child. */
export function resizeAges(ages: string[], count: number): string[] {
    return Array.from(
        { length: Math.max(0, count) },
        (_, index) => ages[index] ?? '',
    );
}

export function resizeBeds(bedIds: string[], count: number): string[] {
    return Array.from(
        { length: Math.max(1, count) },
        (_, index) => bedIds[index] ?? '',
    );
}

/** Whether a line has what the quote needs: the room type, plan, both dates, the adults and every child age. */
export function isQuotable(
    line: BookingLine,
    arrival: string,
    departure: string,
): boolean {
    return (
        line.room_type_id !== '' &&
        line.rate_plan_id !== '' &&
        nightsBetween(arrival, departure) > 0 &&
        Number.parseInt(line.adults, 10) >= 1 &&
        line.child_ages.every((age) => /^\d+$/.test(age))
    );
}

const MONEY = /^\d+(\.\d{1,2})?$/;

/** The quote request of a line. An override or add-on amount that is not valid money is left out. */
export function quotePayload(
    line: BookingLine,
    arrival: string,
    departure: string,
    isBed: boolean,
) {
    return {
        room_type_id: line.room_type_id,
        rate_plan_id: line.rate_plan_id,
        arrival_date: arrival,
        departure_date: departure,
        adults: isBed ? 1 : line.adults,
        child_ages: isBed ? [] : line.child_ages.map(Number),
        beds: isBed ? line.beds : 1,
        override_total: MONEY.test(line.override_total)
            ? line.override_total
            : '',
        add_ons: line.add_ons
            .filter((addOn) => addOn.charge_item_id !== '')
            .map((addOn) => ({
                charge_item_id: addOn.charge_item_id,
                quantity: addOn.quantity,
                unit_amount: MONEY.test(addOn.unit_amount)
                    ? addOn.unit_amount
                    : '',
                service_date: addOn.service_date,
            })),
    };
}

/** The request the form posts: the client-only key is dropped and a dorm line sends its beds, not adults and children. */
export function bookingPayload(data: BookingForm, roomTypes: RoomTypeOption[]) {
    return {
        ...data,
        guests: guestsPayload(data.guests),
        lines: data.lines.map((line) => {
            const isBed = roomTypeOf(line, roomTypes)?.isBed === true;
            const { key: _key, ...rest } = line;

            return {
                ...rest,
                adults: isBed ? '1' : line.adults,
                child_ages: isBed ? [] : line.child_ages.map(Number),
                beds: isBed ? line.beds : '1',
                room_id: isBed ? '' : line.room_id,
                bed_ids: isBed ? line.bed_ids : [],
                add_ons: line.add_ons.filter(
                    (addOn) => addOn.charge_item_id !== '',
                ),
            };
        }),
    };
}

/** Moves one add-on from a line to the end of another line, in one change so neither line is left stale. */
export function moveAddOn(
    lines: BookingLine[],
    from: number,
    position: number,
    to: number,
): BookingLine[] {
    if (from === to || lines[from]?.add_ons[position] === undefined) {
        return lines;
    }

    const moved = lines[from].add_ons[position];

    return lines.map((line, index) => {
        if (index === from) {
            return {
                ...line,
                add_ons: line.add_ons.filter((_, at) => at !== position),
            };
        }

        return index === to
            ? { ...line, add_ons: [...line.add_ons, moved] }
            : line;
    });
}

/**
 * The plans a line can pick: the ones sold for its room type, and a plan linked to companies only when the booking's
 * company is one of them. An empty `roomTypeId` keeps every room type.
 */
export function plansFor(
    plans: App.Data.Reservations.RatePlanOptionData[],
    roomTypeId: string,
    companyId: string,
): App.Data.Reservations.RatePlanOptionData[] {
    return plans.filter(
        (plan) =>
            (roomTypeId === '' ||
                plan.roomTypeIds.includes(Number(roomTypeId))) &&
            (plan.companyIds.length === 0 ||
                (companyId !== '' &&
                    plan.companyIds.includes(Number(companyId)))),
    );
}
