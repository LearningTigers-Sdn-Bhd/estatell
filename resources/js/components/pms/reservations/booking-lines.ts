import type { BookingGuest } from '@/components/pms/reservations/booking-guests';
import { guestsPayload } from '@/components/pms/reservations/booking-guests';
import {
    boatTripPayload,
    NOT_SET,
    type BoatChoice,
} from '@/components/pms/reservations/boat-trips';

/** One booking line of the form: a room type and rate plan, the party, and what is picked for it. */
export type BookingAddOn = {
    charge_item_id: string;
    quantity: string;
    unit_amount: string;
    service_date: string;
    /** Client only: put there by the form for a charge item that is added to every new booking. */
    auto?: boolean;
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
    /** Charge item ids of the auto-add items staff removed from this line. The server logs them. */
    declined_auto_add_ons: string[];
    room_id: string;
    bed_ids: string[];
    /** Day use only: the number of hours, as text. */
    hours: string;
};

export type BookingForm = {
    /** `overnight` or `day_use`: it applies to every line. */
    stay_type: 'overnight' | 'day_use';
    /** Day use only: the start of every block, `H:i`. */
    start_time: string;
    /** The id of the booking source, as text. */
    booking_source_id: string;
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
    /** Past stay only: why the stay is entered late. */
    reason?: string;
    /** Past stay only: still in the house, or already checked out. */
    status?: 'in_house' | 'checked_out';
    /** Past stay, Migrated stay and Old stay: which of the three the form saves. */
    mode?: 'past' | 'migrated' | 'old';
    /** Migrated stay only: `one_amount` or `copy_line_by_line`. */
    old_folio?: string;
    /** Migrated stay, One amount: what the guest still owes in the old system. Minus is a credit. */
    opening_amount?: string;
    /** Migrated stay, One amount: the folio number in the old system. */
    legacy_number?: string;
    guests: BookingGuest[];
    lines: BookingLine[];
    /** Overnight only: the Boat in and Boat out picks, applied to every line. A day use block takes none. */
    boat_in: BoatChoice;
    boat_out: BoatChoice;
    /** Walk-in only, with `payment.record`: the Payment section. The server answers every amount; `expected_total` is the total the rail showed. */
    collect_room_charges?: boolean;
    collect_deposit?: boolean;
    payment_method_id?: string;
    reference?: string;
    expected_total?: string;
    /** Walk-in only, with `front-desk.check-in-without-card`: check every unit in with its registration card unsigned. */
    without_card?: boolean;
};

type RoomTypeOption = App.Data.Reservations.RoomTypeOptionData;

let lineCount = 0;

type ChargeItemOption = App.Data.Reservations.ChargeItemOptionData;

const NIGHTLY = ['per_night', 'per_guest_night'];

/**
 * The add-ons a new line starts with: every charge item that is added to every new booking. A day use block has no
 * nights, so it gets only the items that do not charge by the night.
 */
export function autoAddOns(
    chargeItems: ChargeItemOption[],
    stayType: BookingForm['stay_type'],
): BookingAddOn[] {
    return chargeItems
        .filter(
            (item) =>
                item.autoAdd &&
                (stayType !== 'day_use' ||
                    !NIGHTLY.includes(item.chargingUnit)),
        )
        .map((item) => ({
            charge_item_id: String(item.id),
            quantity: '1',
            unit_amount: item.defaultAmount ?? '',
            service_date: '',
            auto: true,
        }));
}

/** Takes one add-on off a line. Removing an automatic one is remembered, so the server can log it. */
export function removeAddOn(line: BookingLine, position: number): BookingLine {
    const removed = line.add_ons[position];

    return {
        ...line,
        add_ons: line.add_ons.filter((_, at) => at !== position),
        declined_auto_add_ons:
            removed?.auto === true &&
            !line.declined_auto_add_ons.includes(removed.charge_item_id)
                ? [...line.declined_auto_add_ons, removed.charge_item_id]
                : line.declined_auto_add_ons,
    };
}

export function emptyLine(autoAdds: BookingAddOn[] = []): BookingLine {
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
        add_ons: autoAdds.map((addOn) => ({ ...addOn })),
        declined_auto_add_ons: [],
        room_id: '',
        bed_ids: [],
        hours: '',
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

export type UnitOption = {
    value: string;
    label: string;
    isBed: boolean;
    /** How many guests the unit holds: one for a bed, the line's adults and children for a room. */
    capacity: number;
};

/** A room line holds its adults and children, at least one guest. `UnitCapacity` on the server agrees. */
function roomCapacity(line: BookingLine): number {
    const adults = Number.parseInt(line.adults, 10);

    return Math.max(
        1,
        (Number.isFinite(adults) ? adults : 0) + line.child_ages.length,
    );
}

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
                capacity: roomType.isBed ? 1 : roomCapacity(line),
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

const MONTHS = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
];

/**
 * "1–9 Oct" for the nights before go-live: from the arrival to the day before go-live. Empty when the stay does not
 * start before go-live. A range over two months reads "30 Sep–9 Oct".
 */
export function beforeGoLiveRange(arrival: string, goLive: string): string {
    if (!arrival || !goLive || arrival >= goLive) {
        return '';
    }

    const last = addDays(goLive, -1);
    const label = (date: string, withMonth: boolean) => {
        const [, month, day] = date.split('-').map(Number);
        const name = MONTHS[month - 1];

        return withMonth ? `${day} ${name}` : String(day);
    };

    return arrival.slice(0, 7) === last.slice(0, 7)
        ? `${label(arrival, false)}–${label(last, true)}`
        : `${label(arrival, true)}–${label(last, true)}`;
}

/**
 * A Migrated stay quote without the nights before go-live, which are never charged: the line and unit totals count
 * only the nights from go-live. A price override is spread evenly over the nights, so its total is cut in proportion.
 * The nights stay in the quote, so the night table can say "before go-live". Display only: the server charges.
 */
export function chargeableQuote(
    quote: App.Data.Reservations.BookingQuoteData | null,
    goLive: string | null,
    beds: number,
): App.Data.Reservations.BookingQuoteData | null {
    if (quote === null || goLive === null || quote.unitTotal === null) {
        return quote;
    }

    const before = quote.nights.filter((night) => night.date < goLive);

    if (before.length === 0) {
        return quote;
    }

    const total = Math.round(Number(quote.unitTotal) * 100);
    const kept = quote.isOverridden
        ? Math.round(
              (total * (quote.nights.length - before.length)) /
                  quote.nights.length,
          )
        : total -
          before.reduce(
              (sum, night) => sum + Math.round(Number(night.amount) * 100),
              0,
          );
    const unit = Math.max(0, kept);

    return {
        ...quote,
        unitTotal: (unit / 100).toFixed(2),
        lineTotal: ((unit * Math.max(1, beds)) / 100).toFixed(2),
    };
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

/**
 * The party of the booking on its stay: the guests of every unit, counted for the boat seats. A bed unit holds one guest.
 */
export function bookingSeats(
    lines: BookingLine[],
    roomTypes: RoomTypeOption[],
): number {
    return lines.reduce((sum, line) => {
        const isBed =
            roomTypes.find((type) => String(type.id) === line.room_type_id)
                ?.isBed === true;
        const adults = Number.parseInt(line.adults, 10) || 0;
        const people = isBed ? 1 : adults + line.child_ages.length;

        return sum + unitsInLine(line, roomTypes) * people;
    }, 0);
}

/** The add-ons that have a charge item, without the client-only `auto` tag. */
function sendableAddOns(addOns: BookingAddOn[]) {
    return addOns
        .filter((addOn) => addOn.charge_item_id !== '')
        .map(({ auto: _auto, ...rest }) => rest);
}

/** The request the form posts: the client-only key is dropped and a dorm line sends its beds, not adults and children. */
export function bookingPayload(data: BookingForm, roomTypes: RoomTypeOption[]) {
    if (data.stay_type === 'day_use') {
        return {
            ...data,
            departure_date: data.arrival_date,
            boat_in: null,
            boat_out: null,
            guests: guestsPayload(data.guests),
            lines: data.lines.map((line) => {
                const { key: _key, ...rest } = line;

                return {
                    ...rest,
                    child_ages: line.child_ages.map(Number),
                    beds: '1',
                    bed_ids: [],
                    discount_id: '',
                    add_ons: sendableAddOns(line.add_ons),
                };
            }),
        };
    }

    return {
        ...data,
        start_time: '',
        boat_in: boatTripPayload(data.boat_in ?? NOT_SET, data.arrival_time),
        boat_out: boatTripPayload(
            data.boat_out ?? NOT_SET,
            data.departure_time,
        ),
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
                hours: '',
                add_ons: sendableAddOns(line.add_ons),
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
 * The plans a line can pick: the ones sold for its room type, and a plan limited to companies only when the booking's
 * company can use it at the front desk (All companies with Front desk on once any company is picked; Only these
 * companies when the company is listed with Front desk on), and a plan limited to local or international companies only when the company is in that market. An empty `roomTypeId` keeps every room type. A day use booking offers only day use plans and an
 * overnight booking only the others.
 */
export function plansFor(
    plans: App.Data.Reservations.RatePlanOptionData[],
    roomTypeId: string,
    companyId: string,
    dayUse = false,
): App.Data.Reservations.RatePlanOptionData[] {
    return plans.filter(
        (plan) =>
            plan.isDayUse === dayUse &&
            (roomTypeId === '' ||
                plan.roomTypeIds.includes(Number(roomTypeId))) &&
            planOpenToCompany(plan, companyId),
    );
}

function planOpenToCompany(
    plan: App.Data.Reservations.RatePlanOptionData,
    companyId: string,
): boolean {
    if (plan.companyAccess === 'everyone') {
        return true;
    }

    if (companyId === '') {
        return false;
    }

    if (
        plan.companyMarket !== 'all' &&
        !plan.marketCompanyIds.includes(Number(companyId))
    ) {
        return false;
    }

    return plan.companyAccess === 'all'
        ? plan.companyAtDesk
        : plan.companyIds.includes(Number(companyId));
}
