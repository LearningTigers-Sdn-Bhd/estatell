type Row = App.Data.StayView.RatePriceRowData;
type Day = App.Data.StayView.DateRateData;

export type RateChoice = {
    ratePlanId: number | null;
};

/** The rate plans that have a nightly rate, once each, in the property's order. */
export function planChoices(rates: Row[]): { id: number; name: string }[] {
    const seen = new Map<number, string>();

    rates.forEach((row) => {
        if (!seen.has(row.ratePlanId)) {
            seen.set(row.ratePlanId, row.ratePlanName);
        }
    });

    return [...seen].map(([id, name]) => ({ id, name }));
}

/** Keep a saved plan while it still exists, and fall back to the first plan. */
export function resolveChoice(rates: Row[], saved: RateChoice): RateChoice {
    const plans = planChoices(rates);

    return {
        ratePlanId:
            plans.find((plan) => plan.id === saved.ratePlanId)?.id ??
            plans[0]?.id ??
            null,
    };
}

/** The prices of the chosen plan by room type, then by date. A room type that does not sell the plan has none. */
export function pricesByRoomType(
    rates: Row[],
    choice: RateChoice,
): Map<number, Map<string, Day>> {
    return new Map(
        rates
            .filter((row) => row.ratePlanId === choice.ratePlanId)
            .map((row) => [
                row.roomTypeId,
                new Map(row.days.map((day) => [day.date, day])),
            ]),
    );
}

/** The price of every plan that sells a room type on one date, in the property's order. */
export function plansOnDate(
    rates: Row[],
    roomTypeId: number,
    date: string,
): { id: number; name: string; day: Day | undefined }[] {
    return rates
        .filter((row) => row.roomTypeId === roomTypeId)
        .map((row) => ({
            id: row.ratePlanId,
            name: row.ratePlanName,
            day: row.days.find((day) => day.date === date),
        }));
}
