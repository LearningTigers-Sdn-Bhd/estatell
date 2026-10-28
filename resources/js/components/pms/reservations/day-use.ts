import type { BookingLine } from '@/components/pms/reservations/booking-lines';

type Offer = App.Data.Reservations.DayUseOfferData;
type RoomType = App.Data.Reservations.RoomTypeOptionData;
type Plan = App.Data.Reservations.RatePlanOptionData;

/** The two ways a reservation is sold. A reservation is all of one or all of the other. */
export type StayType = 'overnight' | 'day_use';

/** `H:i` as minutes since midnight, or null when it is not a time. */
function minutes(time: string): number | null {
    const match = /^(\d{2}):(\d{2})/.exec(time);

    return match ? Number(match[1]) * 60 + Number(match[2]) : null;
}

function format(total: number): string {
    return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
}

/**
 * The next half hour after `time`: 10:12 gives 10:30 and 10:30 gives 11:00. The last start of a day is 23:30, so a late
 * time stays there.
 */
export function nextHalfHour(time: string): string {
    const now = minutes(time);

    if (now === null) {
        return '';
    }

    return format(Math.min(23 * 60 + 30, (Math.floor(now / 30) + 1) * 30));
}

/** Whether a start time is on the hour or the half hour. */
export function isHalfHour(time: string): boolean {
    const value = minutes(time);

    return value !== null && value % 30 === 0;
}

/**
 * An `H:i` time as the browser shows it in a time input (02:00 PM or 14:00), so the Start field and the end time next to it
 * read the same. A bad time comes back as it was.
 */
export function timeText(time: string): string {
    const total = minutes(time);

    if (total === null) {
        return time;
    }

    return new Intl.DateTimeFormat(undefined, {
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'UTC',
    }).format(new Date(Date.UTC(1970, 0, 1, 0, total)));
}

/** "1 hour" or "4 hours". */
export function hoursText(hours: number): string {
    return `${hours} ${hours === 1 ? 'hour' : 'hours'}`;
}

/** The Hours option: "4 hours · MYR 95.00". `money` formats the amount. */
export function offerLabel(offer: Offer, money: (amount: string) => string) {
    return `${hoursText(offer.hours)} · ${money(offer.amount)}`;
}

/** The hours options of a line from the server's offers. */
export function offerOptions(
    offers: Offer[],
    money: (amount: string) => string,
): { value: string; label: string }[] {
    return offers.map((offer) => ({
        value: String(offer.hours),
        label: offerLabel(offer, money),
    }));
}

/** The end time of the picked hours, `H:i`, or null while no hours are picked. */
export function endsAt(offers: Offer[], hours: string): string | null {
    return (
        offers.find((offer) => String(offer.hours) === hours)?.endsAt ?? null
    );
}

/** The room types day use is sold on: those sold per room, with or without a Day use plan. */
export function dayUseRoomTypes(roomTypes: RoomType[]): RoomType[] {
    return roomTypes.filter((type) => !type.isBed);
}

/** The value of the **No plan · enter price** option in the Plan select. */
export const ENTERED_PLAN = 'entered';

/** Whether a line is on the room type's per-night plan, which is an entered price. */
export function isEnteredPlan(
    ratePlanId: string,
    roomType: RoomType | undefined,
): boolean {
    return (
        ratePlanId !== '' &&
        roomType?.enteredPlanId != null &&
        String(roomType.enteredPlanId) === ratePlanId
    );
}

/** The Plan select value of a line: the plan id, or `ENTERED_PLAN` for an entered price. */
export function planSelectValue(
    ratePlanId: string,
    roomType: RoomType | undefined,
): string {
    return isEnteredPlan(ratePlanId, roomType) ? ENTERED_PLAN : ratePlanId;
}

/** The plan id a Plan select value stands for. */
export function planIdFromSelect(
    value: string,
    roomType: RoomType | undefined,
): string {
    return value === ENTERED_PLAN && roomType?.enteredPlanId != null
        ? String(roomType.enteredPlanId)
        : value;
}

/** The Plan options: the Day use plans, then **No plan · enter price**, disabled when the room type has no per-night plan. */
export function dayUsePlanOptions(
    plans: Plan[],
    roomType: RoomType | undefined,
): {
    value: string;
    label: string;
    disabled?: boolean;
    description?: string;
}[] {
    const entered =
        roomType === undefined || roomType.enteredPlanId == null
            ? {
                  disabled: true,
                  description:
                      'Add a per-night plan for this room type to enter a price.',
              }
            : {};

    return [
        ...plans.map((plan) => ({ value: String(plan.id), label: plan.name })),
        { value: ENTERED_PLAN, label: 'No plan · enter price', ...entered },
    ];
}

/** The end time of an entered price block, `H:i`, or null when the start or hours are not usable or it would pass midnight. */
export function enteredEndsAt(start: string, hours: string): string | null {
    const from = minutes(start);
    const count = /^\d+$/.test(hours) ? Number(hours) : 0;

    if (from === null || count < 1 || from + count * 60 >= 24 * 60) {
        return null;
    }

    return format(from + count * 60);
}

/**
 * The hours of a block from `start` to `end` (`H:i`), rounded up to whole hours, as text. Empty when the end is not
 * after the start or the block is longer than the day.
 */
export function hoursBetween(start: string, end: string): string {
    const from = minutes(start);
    const to = minutes(end);

    if (from === null || to === null || to <= from) {
        return '';
    }

    return String(Math.ceil((to - from) / 60));
}

/**
 * Whether a line on a Day use plan has to switch to an entered price: the plan's answer is in and it sells no hours at
 * that start, or not the hours picked.
 */
export function needsEnteredPrice(
    offers: Offer[],
    loaded: boolean,
    hours: string,
): boolean {
    return (
        loaded &&
        (offers.length === 0 ||
            (hours !== '' &&
                !offers.some((offer) => String(offer.hours) === hours)))
    );
}

/** The note shown after a line switches to an entered price. */
export function enteredSwitchNote(planName: string): string {
    return `Outside what ${planName} sells. Enter the price.`;
}

/** Whether a day use line has what the quote needs. */
export function isDayUseQuotable(
    line: BookingLine,
    date: string,
    start: string,
): boolean {
    return (
        line.room_type_id !== '' &&
        line.rate_plan_id !== '' &&
        line.hours !== '' &&
        date !== '' &&
        isHalfHour(start) &&
        Number.parseInt(line.adults, 10) >= 1 &&
        line.child_ages.every((age) => /^\d+$/.test(age))
    );
}

/** The quote request of a day use line. */
export function dayUseQuotePayload(
    line: BookingLine,
    date: string,
    start: string,
) {
    return {
        room_type_id: line.room_type_id,
        rate_plan_id: line.rate_plan_id,
        stay_type: 'day_use' as const,
        arrival_date: date,
        start_time: start,
        hours: line.hours,
        adults: line.adults,
        child_ages: line.child_ages.map(Number),
        override_total: /^\d+(\.\d{1,2})?$/.test(line.override_total)
            ? line.override_total
            : '',
        add_ons: line.add_ons
            .filter((addOn) => addOn.charge_item_id !== '')
            .map((addOn) => ({
                charge_item_id: addOn.charge_item_id,
                quantity: addOn.quantity,
                unit_amount: addOn.unit_amount,
                service_date: addOn.service_date,
            })),
    };
}
