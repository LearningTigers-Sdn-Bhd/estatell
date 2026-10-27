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

/** The room types that have a day use plan and are sold per room. */
export function dayUseRoomTypes(
    roomTypes: RoomType[],
    plans: Plan[],
): RoomType[] {
    return roomTypes.filter(
        (type) =>
            !type.isBed &&
            plans.some(
                (plan) => plan.isDayUse && plan.roomTypeIds.includes(type.id),
            ),
    );
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
