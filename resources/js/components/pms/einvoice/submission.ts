import { folio as folioTab } from '@/routes/reservations';

export type Badge = { value: string; label: string; tone: App.Enums.Tone };

/** Whose details LHDN rejected: the buyer's, the hotel's (E-invoice settings), the MyInvois login, or none (retry). */
export type Where = 'buyer' | 'hotel' | 'login' | 'retry';

export type Submission = {
    id: number;
    number: string;
    kind: 'invoice' | 'credit_note' | 'refund_note' | 'summary';
    status: Badge;
    amounts: {
        subtotal: string | null;
        tax: string | null;
        total: string | null;
    };
    period: { from: string; to: string } | null;
    buyer: {
        name: string;
        tax_number: string | null;
        id_label: string | null;
        id_number: string | null;
        email: string | null;
        phone: string | null;
        address: string | null;
    } | null;
    stays_included: number | null;
    stays: {
        folio_id: number;
        reservation_id: number;
        reference: string | null;
        guest: string | null;
        folio_number: string | null;
        closed_on: string | null;
        net: string;
        tax: string;
        total: string;
    }[];
    reservation: {
        id: number;
        reference: string;
        folio_id: number;
        folio_number: string;
    } | null;
    ar_invoice: {
        id: number;
        number: string;
        company: string | null;
        company_id: number;
    } | null;
    original: { id: number; number: string } | null;
    credited: string | null;
    credit_notes: {
        id: number;
        number: string;
        kind: { value: string; label: string };
        amount: string | null;
        status: Badge;
        issued_at: string | null;
    }[];
    accepted_at: string | null;
    cancelled_at: string | null;
    cancel_reason: string | null;
    cancel_until: string | null;
    can_cancel: boolean;
    can_credit_note: boolean;
    can_check_status: boolean;
    guest_link: string | null;
    guest_email: {
        to: string | null;
        subject: string;
        paragraphs: string[];
        details: Record<string, string>;
    } | null;
    lhdn_reference: string | null;
    reasons: { message: string; fix: string; where: Where }[];
    replaced_by: {
        id: number;
        number: string;
        kind: { value: string; label: string };
        status: Badge;
    } | null;
    can_resubmit: boolean;
    can_find_tax_number: boolean;
    history: { label: string; at: string | null }[];
};

export const VISIBLE_CREDIT_NOTES = 3;

export const KIND_LABEL: Record<Submission['kind'], string> = {
    invoice: 'E-invoice',
    credit_note: 'Credit note',
    refund_note: 'Refund note',
    summary: 'Monthly summary',
};

/** A credit or refund note: it adjusts another e-invoice and copies its buyer. */
export function isAdjustment(submission: Submission): boolean {
    return (
        submission.kind === 'credit_note' || submission.kind === 'refund_note'
    );
}

export function folioUrl(
    submission: Submission,
    openForm = false,
): string | null {
    if (!submission.reservation) {
        return null;
    }

    return folioTab.url(
        { reservation: submission.reservation.id },
        {
            query: {
                folio: String(submission.reservation.folio_id),
                ...(openForm ? { e_invoice: 'fix' } : {}),
            },
        },
    );
}

/**
 * What a rejection needs: the buyer's details fixed where they are kept (the folio's Issue e-invoice form, or the
 * company record for a city ledger invoice), the hotel's in E-invoice settings, or nothing but sending it again.
 */
export function rejection(submission: Submission) {
    const has = (where: Where) =>
        submission.reasons.some((reason) => reason.where === where);

    return {
        buyer: submission.reasons.length === 0 || has('buyer'),
        settings: has('hotel') || has('login'),
    };
}
