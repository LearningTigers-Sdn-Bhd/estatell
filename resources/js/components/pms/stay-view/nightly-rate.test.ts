import { expect, test } from 'vite-plus/test';
import {
    planChoices,
    pricesByDate,
    resolveChoice,
    roomTypeChoices,
} from './nightly-rate';

const row = (
    roomTypeId: number,
    ratePlanId: number,
    days: App.Data.StayView.DateRateData[] = [],
) => ({
    roomTypeId,
    roomTypeName: `Type ${roomTypeId}`,
    ratePlanId,
    ratePlanName: `Plan ${ratePlanId}`,
    days,
});

const rates = [
    row(1, 10, [{ date: '2026-10-14', amount: '240.00', closed: false }]),
    row(1, 11),
    row(2, 10),
];

test('lists room types once and the plans that sell one, in order', () => {
    expect(roomTypeChoices(rates).map((type) => type.id)).toEqual([1, 2]);
    expect(planChoices(rates, 1).map((plan) => plan.id)).toEqual([10, 11]);
    expect(planChoices(rates, null)).toEqual([]);
});

test('defaults to the first room type and its first plan', () => {
    expect(
        resolveChoice(rates, { roomTypeId: null, ratePlanId: null }),
    ).toEqual({
        roomTypeId: 1,
        ratePlanId: 10,
    });
});

test('keeps a saved choice and repairs one that no longer exists', () => {
    expect(resolveChoice(rates, { roomTypeId: 1, ratePlanId: 11 })).toEqual({
        roomTypeId: 1,
        ratePlanId: 11,
    });
    expect(resolveChoice(rates, { roomTypeId: 2, ratePlanId: 11 })).toEqual({
        roomTypeId: 2,
        ratePlanId: 10,
    });
    expect(resolveChoice(rates, { roomTypeId: 99, ratePlanId: 99 })).toEqual({
        roomTypeId: 1,
        ratePlanId: 10,
    });
    expect(resolveChoice([], { roomTypeId: 1, ratePlanId: 10 })).toEqual({
        roomTypeId: null,
        ratePlanId: null,
    });
});

test('reads the prices of the chosen plan by date', () => {
    const prices = pricesByDate(rates, { roomTypeId: 1, ratePlanId: 10 });

    expect(prices.get('2026-10-14')?.amount).toBe('240.00');
    expect(prices.get('2026-10-15')).toBeUndefined();
    expect(pricesByDate(rates, { roomTypeId: 9, ratePlanId: 9 }).size).toBe(0);
});
