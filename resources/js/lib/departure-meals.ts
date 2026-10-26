type MealPeriod = App.Data.Meals.MealPeriodData;

/**
 * The meal names a guest on a departure can get, for the departure form preview. Mirrors `MealTimeRule` on the
 * server: Boat in counts a meal that ends after the boat arrives, Boat out a meal that starts before it leaves.
 * Display only: after saving, the Meals column shows the server's list. Times are `HH:MM`.
 */
export function departureMeals(
    direction: string,
    leavesAt: string,
    arrivesAt: string,
    mealPeriods: MealPeriod[],
): string[] {
    const matches =
        direction === 'in'
            ? mealPeriods.filter(
                  (mealPeriod) => arrivesAt.slice(0, 5) < mealPeriod.endsAt,
              )
            : mealPeriods.filter(
                  (mealPeriod) => leavesAt.slice(0, 5) > mealPeriod.startsAt,
              );

    return matches.map((mealPeriod) => mealPeriod.name);
}
