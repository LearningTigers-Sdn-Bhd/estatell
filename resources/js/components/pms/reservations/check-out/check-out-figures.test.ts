import { expect, test } from 'vite-plus/test';
import { checkOutFigures } from './check-out-figures';
import type { CheckOutFigureInput } from './check-out-figures';

type Preview = App.Data.Reservations.UnitCheckOutPreviewData;

function preview(overrides: Partial<Preview> = {}): Preview {
    return {
        balance: '0.00',
        owing: '0.00',
        depositHeld: '0.00',
        refundDefault: '0.00',
        depositMethod: null,
        canRefund: true,
        folioKey: null,
        error: null,
        canCollect: true,
        early: null,
        rooms: [],
        otherLineIds: [],
        receivesScheduled: false,
        canMoveCharges: false,
        refundable: '0.00',
        refundParts: [],
        charges: '0.00',
        paid: '0.00',
        pendingLines: [],
        pendingTotal: '0.00',
        overdue: null,
        auditPending: null,
        damageItem: null,
        damageTotal: null,
        leftYesterdayChoice: false,
        leftYesterdayBlocked: null,
        ...overrides,
    };
}

function figures(
    overrides: Partial<Preview> = {},
    input: Partial<CheckOutFigureInput> = {},
) {
    return checkOutFigures({
        preview: preview(overrides),
        canWaiveFees: true,
        canCorrectMoney: true,
        doNotCharge: false,
        keepDamage: false,
        damageTyped: '',
        depositChoice: '',
        refundInput: '',
        ...input,
    });
}

const early = {
    departure: '2026-10-23',
    nightsRemoved: 1,
    fee: '40.00',
    feeTotal: '42.40',
    withinFreeWindow: false,
    newDeparture: '2026-10-22',
    leftYesterdayAvailable: false,
};

test('a folio at 0 is settled with nothing to collect or refund', () => {
    const result = figures();

    expect(result.settled).toBe(true);
    expect(result.collecting).toBe(false);
    expect(result.refunding).toBe(false);
});

test('no preview yet is not settled', () => {
    expect(
        checkOutFigures({
            preview: null,
            canWaiveFees: false,
            canCorrectMoney: true,
            doNotCharge: false,
            keepDamage: false,
            damageTyped: '',
            depositChoice: '',
            refundInput: '',
        }).settled,
    ).toBe(false);
});

test('an owing folio uses the deposit toward the bill by default and collects the rest', () => {
    const result = figures({
        balance: '100.00',
        charges: '250.00',
        paid: '50.00',
        depositHeld: '100.00',
        refundable: '150.00',
    });

    expect(result.deposit).toBe('use');
    expect(result.refunding).toBe(false);
    expect(result.collectAmount).toBe(100);
    expect(result.settled).toBe(true);
});

test('refunding the deposit while owing refunds it and collects the whole balance plus the deposit', () => {
    const result = figures(
        {
            balance: '100.00',
            depositHeld: '100.00',
            refundable: '150.00',
        },
        { depositChoice: 'refund' },
    );

    expect(result.refundAmount).toBe('100.00');
    expect(result.collectAmount).toBe(200);
    expect(result.remaining).toBe(0);
});

test('a credit above the deposit is refunded as a whole and cannot change', () => {
    const result = figures(
        {
            balance: '-150.00',
            depositHeld: '100.00',
            refundable: '200.00',
            refundDefault: '150.00',
        },
        { refundInput: '10.00' },
    );

    expect(result.creditAbove).toBe(true);
    expect(result.refundAmount).toBe('150.00');
    expect(result.due).toBe(0);
    expect(result.settled).toBe(true);
});

test('a folio a payment already settled offers no refund', () => {
    const settled = figures({
        balance: '0.00',
        paid: '-396.00',
        refundable: '396.00',
    });
    const owing = figures({
        balance: '96.00',
        paid: '-300.00',
        refundable: '300.00',
    });

    expect(settled.canRefund).toBe(false);
    expect(settled.settled).toBe(true);
    expect(owing.canRefund).toBe(false);
    expect(owing.collectAmount).toBe(96);
});

test('a folio the deposit already settled has nothing to refund', () => {
    const result = figures({ balance: '0.00', depositHeld: '100.00' });

    expect(result.depositUsed).toBe(true);
});

test('the early departure fee counts on the folio unless it is waived', () => {
    const base = { balance: '0.00', charges: '300.00', early };

    expect(figures(base).feeOnFolio).toBe(42.4);
    expect(figures(base).balance).toBe(42.4);

    const waived = figures(base, { doNotCharge: true });

    expect(waived.waived).toBe(true);
    expect(waived.feeOnFolio).toBe(0);
});

test('Do not charge needs the permission and a fee', () => {
    expect(
        figures({ early }, { canWaiveFees: false, doNotCharge: true }).waived,
    ).toBe(false);
    expect(
        figures(
            { early: { ...early, fee: '0.00', feeTotal: '0.00' } },
            { doNotCharge: true },
        ).waived,
    ).toBe(false);
});

test('a damage charge is added with tax, forces the deposit to be used and waits for its price', () => {
    const base = {
        balance: '-100.00',
        depositHeld: '100.00',
        refundable: '100.00',
        damageItem: {} as Preview['damageItem'],
    };
    const priced = figures(
        { ...base, damageTotal: '31.80' },
        { keepDamage: true, damageTyped: '30.00' },
    );

    expect(priced.damageOnFolio).toBe(31.8);
    expect(priced.balance).toBeCloseTo(-68.2);

    expect(
        figures(
            { ...base, balance: '50.00', damageTotal: '31.80' },
            { keepDamage: true, damageTyped: '30.00', depositChoice: 'refund' },
        ).deposit,
    ).toBe('use');

    expect(
        figures(base, { keepDamage: true, damageTyped: '30.00' }).settled,
    ).toBe(false);
});

test('a user who may not correct money cannot refund', () => {
    const result = figures(
        { balance: '-50.00', depositHeld: '50.00', refundable: '50.00' },
        { canCorrectMoney: false },
    );

    expect(result.canRefund).toBe(false);
    expect(result.refunding).toBe(false);
});

test('a preview with an error is never settled', () => {
    expect(figures({ error: 'Nope' }).settled).toBe(false);
});

test('the deposit is a choice only while the guest owes money before any damage', () => {
    const owing = {
        balance: '100.00',
        depositHeld: '100.00',
        refundable: '100.00',
    };
    const settledByDeposit = {
        balance: '0.00',
        depositHeld: '100.00',
        refundable: '100.00',
    };

    expect(figures(owing).choosesDeposit).toBe(true);
    expect(figures(settledByDeposit).choosesDeposit).toBe(false);
    expect(figures({ ...owing, depositHeld: '0.00' }).choosesDeposit).toBe(
        false,
    );
    expect(figures(owing, { canCorrectMoney: false }).choosesDeposit).toBe(
        false,
    );
});

test('a damage charge added to a folio the deposit settled does not turn the checkbox into the cards', () => {
    const result = figures(
        {
            balance: '0.00',
            depositHeld: '100.00',
            refundable: '100.00',
            damageItem: {} as Preview['damageItem'],
            damageTotal: '30.00',
        },
        { keepDamage: true, damageTyped: '30.00' },
    );

    expect(result.owing).toBe(30);
    expect(result.choosesDeposit).toBe(false);
});
