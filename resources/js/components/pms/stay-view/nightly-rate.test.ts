import { expect, test } from 'vite-plus/test';
import {
    planChoices,
    plansOnDate,
    pricesByRoomType,
    resolveChoice,
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
    row(2, 10, [{ date: '2026-10-14', amount: '300.00', closed: true }]),
];

test('lists each plan once, in order', () => {
    expect(planChoices(rates).map((plan) => plan.id)).toEqual([10, 11]);
    expect(planChoices([])).toEqual([]);
});

test('keeps a saved plan and falls back to the first one', () => {
    expect(resolveChoice(rates, { ratePlanId: 11 })).toEqual({
        ratePlanId: 11,
    });
    expect(resolveChoice(rates, { ratePlanId: null })).toEqual({
        ratePlanId: 10,
    });
    expect(resolveChoice(rates, { ratePlanId: 99 })).toEqual({
        ratePlanId: 10,
    });
    expect(resolveChoice([], { ratePlanId: 10 })).toEqual({
        ratePlanId: null,
    });
});

test('reads the prices of the chosen plan by room type and date', () => {
    const prices = pricesByRoomType(rates, { ratePlanId: 10 });

    expect(prices.get(1)?.get('2026-10-14')?.amount).toBe('240.00');
    expect(prices.get(2)?.get('2026-10-14')?.closed).toBe(true);
    expect(prices.get(1)?.get('2026-10-15')).toBeUndefined();
});

test('a room type that does not sell the plan has no prices', () => {
    const prices = pricesByRoomType(rates, { ratePlanId: 11 });

    expect(prices.has(1)).toBe(true);
    expect(prices.has(2)).toBe(false);
});

test('lists every plan of a room type on a date, with a price or none', () => {
    const plans = plansOnDate(rates, 1, '2026-10-14');

    expect(plans.map((plan) => plan.id)).toEqual([10, 11]);
    expect(plans[0].day?.amount).toBe('240.00');
    expect(plans[1].day).toBeUndefined();
    expect(plansOnDate(rates, 9, '2026-10-14')).toEqual([]);
});
