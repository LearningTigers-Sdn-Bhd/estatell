import { expect, test } from 'vite-plus/test';
import { paymentTotal } from './walk-in-payment';

const payment: App.Data.Reservations.WalkInPaymentData = {
    roomCharges: '432.00',
    roomChargesHidden: false,
    taxes: [{ name: 'Tourism tax', amount: '32.00', exempt: false }],
    taxesTotal: '32.00',
    deposit: { amount: '100.00', units: 2, total: '200.00' },
    totalWithRoomCharges: '632.00',
    totalWithoutRoomCharges: '232.00',
    errors: {},
};

test('the total is one of the amounts the server answered for the ticked boxes', () => {
    expect(paymentTotal(payment, true, true)).toBe('632.00');
    expect(paymentTotal(payment, false, true)).toBe('232.00');
    expect(paymentTotal(payment, true, false)).toBe('432.00');
    expect(paymentTotal(payment, false, false)).toBe('32.00');
});

test('a billed company counts room charges as unticked, and no deposit leaves the deposit out', () => {
    const billed = {
        ...payment,
        roomChargesHidden: true,
        taxes: [],
        taxesTotal: '0.00',
        totalWithRoomCharges: '200.00',
        totalWithoutRoomCharges: '200.00',
    };

    expect(paymentTotal(billed, true, true)).toBe('200.00');
    expect(paymentTotal(billed, true, false)).toBe('0.00');
    expect(paymentTotal({ ...payment, deposit: null }, false, true)).toBe(
        '32.00',
    );
});
