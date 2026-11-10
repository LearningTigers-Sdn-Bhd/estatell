import type { BookingGuest } from '@/components/pms/reservations/booking-guests';
import type { MyKadData } from '@/hooks/use-mykad-reader';

const CARD_FIELDS = [
    'full_name',
    'nationality_code',
    'date_of_birth',
    'gender',
    'address_line1',
    'address_line2',
    'postal_code',
    'city',
    'state',
    'country_code',
    'identity',
] as const;

/** The guest fields a MyKad fills. Phone, email, Stays in and notes are not on the card. */
export type CardFields = Pick<BookingGuest, (typeof CARD_FIELDS)[number]>;

export function fieldsFromCard(
    card: MyKadData,
    withIdentity = false,
): CardFields {
    return {
        full_name: card.name,
        nationality_code: 'MY',
        date_of_birth: card.date_of_birth,
        gender: card.gender,
        address_line1: card.address_line1,
        address_line2: card.address_line2,
        postal_code: card.postal_code,
        city: card.city,
        state: card.state,
        country_code: 'MY',
        ...(withIdentity
            ? {
                  identity: {
                      identity_document_id: '',
                      identity_type: 'mykad',
                      identity_number: card.formatted_ic || card.ic_number,
                      identity_country_code: 'MY',
                  },
              }
            : {}),
    };
}

/** The values to go back to when a scan is cleared. */
export function pickCardFields(values: CardFields): CardFields {
    return Object.fromEntries(
        CARD_FIELDS.map((field) => [field, values[field]]),
    ) as CardFields;
}

/** A row with no name and no linked guest. Only these become the target by themselves. */
export function isEmptyGuest(
    guest: Pick<BookingGuest, 'full_name' | 'guest'>,
): boolean {
    return guest.guest === null && guest.full_name.trim() === '';
}

/** `auto` is the first empty row, `row` is the row staff turned on, and `off` is no row until staff turn one on. */
export type MykadTarget =
    | { mode: 'auto' }
    | { mode: 'row'; key: string }
    | { mode: 'off' };

export type MykadTargetRow = { key: string; empty: boolean };

/** The one row the next card fills. A turned-on row that is gone falls back to the first empty row. */
export function targetRowKey(
    target: MykadTarget,
    rows: MykadTargetRow[],
): string | null {
    if (target.mode === 'off') {
        return null;
    }

    if (target.mode === 'row' && rows.some((row) => row.key === target.key)) {
        return target.key;
    }

    return rows.find((row) => row.empty)?.key ?? null;
}

export type MykadToggleState = 'off' | 'on' | 'filled';

export function mykadToggleState(
    active: boolean,
    filled: boolean,
): MykadToggleState {
    if (active) {
        return 'on';
    }

    return filled ? 'filled' : 'off';
}
