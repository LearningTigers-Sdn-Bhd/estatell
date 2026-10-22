type Preview = App.Data.Reservations.UnitCheckOutPreviewData;

export type DepositChoice = 'use' | 'refund';

export type CheckOutFigureInput = {
    preview: Preview | null;
    canWaiveFees: boolean;
    canCorrectMoney: boolean;
    /** The Do not charge box. */
    doNotCharge: boolean;
    /** The Keep part for damages box. */
    keepDamage: boolean;
    /** The damage amount when it is a valid amount, else an empty string. */
    damageTyped: string;
    /** The deposit choice the form holds: `use`, `refund` or empty (the default). */
    depositChoice: string;
    /** The refund amount the form holds, empty for the default. */
    refundInput: string;
};

/**
 * What the check-out Dialog shows, worked out from the server's preview and the form's choices. Display math only: the
 * server posts the fee, the damage and the refund and checks the balance again. Mirrors
 * `CheckOutSettlement::refunds()` for the refund default.
 */
export function checkOutFigures({
    preview,
    canWaiveFees,
    canCorrectMoney,
    doNotCharge,
    keepDamage,
    damageTyped,
    depositChoice,
    refundInput,
}: CheckOutFigureInput) {
    const early = preview?.early ?? null;
    const held = Number(preview?.depositHeld ?? 0);
    const refundable = Number(preview?.refundable ?? 0);
    const hasFee = early !== null && Number(early.fee) > 0;
    const showWaive = canWaiveFees && hasFee;
    const waived = showWaive && doNotCharge;
    const feeOnFolio = early !== null && !waived ? Number(early.feeTotal) : 0;
    const damageItem = preview?.damageItem ?? null;
    // The damage posts first and the deposit offsets it. `damageTotal` is the server's figure with tax.
    const damaging = keepDamage && damageItem !== null && held > 0;
    const damageOnFolio =
        damaging && damageTyped !== '' ? Number(preview?.damageTotal ?? 0) : 0;
    const balance = Number(preview?.balance ?? 0) + feeOnFolio + damageOnFolio;
    // The folio block: charges (with the fee) + paid − deposit held = balance.
    const charges = Number(preview?.charges ?? 0) + feeOnFolio;
    const paid = Number(preview?.paid ?? 0);
    const owing = Math.max(balance, 0);
    const canCollect = preview?.canCollect === true;
    const canRefund =
        preview?.canRefund === true && refundable > 0 && canCorrectMoney;
    // A folio in credit can only refund the deposit, so the choice is offered only while the guest owes money. With a
    // damage charge the deposit always goes to the damage and the bill first.
    const deposit = (
        owing > 0 ? (damaging ? 'use' : depositChoice || 'use') : 'refund'
    ) as DepositChoice;
    const refunding = canRefund && deposit === 'refund';
    // The folio credit (after the early departure fee, unless waived). Above the deposit, the credit is refunded as a
    // whole, from the payments in refund order.
    const credit = Math.max(-balance, 0);
    const creditAbove = owing === 0 && credit > held;
    // A folio already at 0 has used the deposit toward the bill: there is nothing to refund.
    const depositUsed = held > 0 && Math.abs(balance) < 0.005;
    // Owing money: refund the whole deposit and collect the rest. Otherwise refund what settles the folio.
    const refundDefault =
        owing > 0
            ? held.toFixed(2)
            : Math.min(credit, creditAbove ? refundable : held).toFixed(2);
    // Above the deposit the amount is the credit and cannot change, so it is read only.
    const refundAmount = refunding
        ? creditAbove || refundInput === ''
            ? refundDefault
            : refundInput
        : '0.00';
    // The parts are the server's for its default; they do not apply when the amount differs (Do not charge).
    const refundParts =
        creditAbove && refundAmount === preview?.refundDefault
            ? (preview?.refundParts ?? [])
            : [];
    const refund = Number(refundAmount);
    const due = balance + refund;
    const collecting = canCollect && due > 0.005;
    const collectAmount = collecting ? due : 0;
    const remaining = due - collectAmount;
    // A ticked damage box needs an amount the server has priced.
    const damageReady = !damaging || preview?.damageTotal != null;
    const settled =
        preview !== null &&
        preview.error === null &&
        damageReady &&
        Math.abs(remaining) < 0.005;

    return {
        held,
        refundable,
        hasFee,
        showWaive,
        waived,
        feeOnFolio,
        damageItem,
        damaging,
        damageOnFolio,
        balance,
        charges,
        paid,
        owing,
        canCollect,
        canRefund,
        deposit,
        refunding,
        credit,
        creditAbove,
        depositUsed,
        refundDefault,
        refundAmount,
        refundParts,
        refund,
        due,
        collecting,
        collectAmount,
        remaining,
        damageReady,
        settled,
    };
}

export type CheckOutFigures = ReturnType<typeof checkOutFigures>;
