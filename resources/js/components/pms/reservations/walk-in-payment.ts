import { bookingPayload } from '@/components/pms/reservations/booking-lines';
import type { BookingForm } from '@/components/pms/reservations/booking-lines';

type Payment = App.Data.Reservations.WalkInPaymentData;
type RoomTypeOption = App.Data.Reservations.RoomTypeOptionData;

/** The form fields of the Payment section. The server never trusts them for an amount: `expected_total` is only compared. */
export const PAYMENT_FIELDS = [
    'collect_room_charges',
    'collect_deposit',
    'payment_method_id',
    'reference',
    'expected_total',
] as const;

/**
 * What the rail posts to `reservations.walk-in-payment`: the whole form, without the payment fields and the notes.
 * It is null while the booking is not complete enough to ask (a line without a room type or plan, no departure, no
 * booker), so the rail does not book a trial for every keystroke.
 */
export function walkInPaymentPayload(
    data: BookingForm,
    roomTypes: RoomTypeOption[],
): Record<string, unknown> | null {
    const booker = data.guests[0];

    if (
        !data.departure_date ||
        !booker ||
        (booker.guest === null && booker.full_name.trim() === '') ||
        data.lines.some((line) => !line.room_type_id || !line.rate_plan_id)
    ) {
        return null;
    }

    const payload: Record<string, unknown> = {
        ...bookingPayload(data, roomTypes),
    };

    for (const field of [...PAYMENT_FIELDS, 'notes']) {
        delete payload[field];
    }

    return payload;
}

/**
 * The total the rail shows and posts as `expected_total` for the ticked boxes. It picks one of the amounts the server
 * answered and adds nothing: with the deposit ticked it is one of the two server totals. Room charges ticked while
 * the company is billed counts as unticked.
 */
export function paymentTotal(
    payment: Payment,
    collectRoomCharges: boolean,
    collectDeposit: boolean,
): string {
    const rooms = collectRoomCharges && !payment.roomChargesHidden;

    if (collectDeposit && payment.deposit !== null) {
        return rooms
            ? payment.totalWithRoomCharges
            : payment.totalWithoutRoomCharges;
    }

    return rooms ? payment.roomCharges : payment.taxesTotal;
}
