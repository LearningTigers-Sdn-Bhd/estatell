type Row = App.Data.StayView.RatePriceRowData;
type Day = App.Data.StayView.DateRateData;

export type RateChoice = {
    roomTypeId: number | null;
    ratePlanId: number | null;
};

/** The room types that have a nightly rate, once each, in the property's order. */
export function roomTypeChoices(rates: Row[]): { id: number; name: string }[] {
    const seen = new Map<number, string>();

    rates.forEach((row) => seen.set(row.roomTypeId, row.roomTypeName));

    return [...seen].map(([id, name]) => ({ id, name }));
}

/** The rate plans that sell a room type, in the property's order. */
export function planChoices(
    rates: Row[],
    roomTypeId: number | null,
): { id: number; name: string }[] {
    return rates
        .filter((row) => row.roomTypeId === roomTypeId)
        .map((row) => ({ id: row.ratePlanId, name: row.ratePlanName }));
}

/**
 * Keep a saved choice while it still exists, and fall back to the first room type and its first plan. A plan that
 * does not sell the chosen room type falls back to that room type's first plan.
 */
export function resolveChoice(rates: Row[], saved: RateChoice): RateChoice {
    const types = roomTypeChoices(rates);
    const roomTypeId =
        types.find((type) => type.id === saved.roomTypeId)?.id ??
        types[0]?.id ??
        null;
    const plans = planChoices(rates, roomTypeId);
    const ratePlanId =
        plans.find((plan) => plan.id === saved.ratePlanId)?.id ??
        plans[0]?.id ??
        null;

    return { roomTypeId, ratePlanId };
}

/** The prices of one plan for one room type by date. */
export function pricesByDate(
    rates: Row[],
    choice: RateChoice,
): Map<string, Day> {
    const row = rates.find(
        (candidate) =>
            candidate.roomTypeId === choice.roomTypeId &&
            candidate.ratePlanId === choice.ratePlanId,
    );

    return new Map((row?.days ?? []).map((day) => [day.date, day]));
}
