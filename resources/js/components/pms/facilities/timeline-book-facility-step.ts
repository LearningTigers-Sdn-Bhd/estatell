/** Who a booking made from the Timeline belongs to. */
export type OwnerType = 'reservation' | 'event';

/** Next on the For step needs a picked reservation, or a picked event. */
export function forStepReady(
    type: OwnerType,
    reservationId: number | null,
    eventId: string,
): boolean {
    return type === 'reservation' ? reservationId !== null : eventId !== '';
}
