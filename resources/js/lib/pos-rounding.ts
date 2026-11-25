/**
 * Client-side preview of MalaysiaCashRounding (BNM 5-sen / 10-sen half-up).
 * Used strictly for cashier display; the server remains the sole source of truth on settlement.
 */
export function cashRoundingAdjustment(
    mode: string,
    policy: string,
    grandTotal: number,
    cashAmount: number,
): number {
    if (mode === 'none' || !(cashAmount > 0)) {
        return 0;
    }

    const base =
        policy === 'whole_bill_when_cash_present' ? grandTotal : cashAmount;
    const sen = Math.round(base * 100);
    const step = mode === 'ten_sen' ? 10 : mode === 'five_sen' ? 5 : 1;

    if (step === 1) {
        return 0;
    }

    const remainder = ((sen % step) + step) % step;
    const roundUp = mode === 'five_sen' ? remainder >= 3 : remainder >= 5;
    const roundedSen = roundUp ? sen + step - remainder : sen - remainder;

    return Math.round(roundedSen - sen) / 100;
}
