/**
 * One line of the portal book page. Numbers are kept as text while the user types. A dorm line has `beds` and always
 * one adult for each bed; a room line has adults and one age for each child.
 */
export type BookLine = {
    key: string;
    room_type_id: string;
    adults: string;
    child_ages: string[];
    beds: string;
    rate_plan_id: string;
    guest_name: string;
    guest_phone: string;
};

export const MAX_LINES = 20;

let lineCounter = 0;

export function newLine(): BookLine {
    lineCounter += 1;

    return {
        key: `line-${lineCounter}`,
        room_type_id: '',
        adults: '2',
        child_ages: [],
        beds: '1',
        rate_plan_id: '',
        guest_name: '',
        guest_phone: '',
    };
}

/** Keep the first `count` ages, and add empty ones (asked for) up to `count`. */
export function resizeAges(ages: string[], count: number): string[] {
    const size = Math.max(0, Math.min(20, count));

    return Array.from({ length: size }, (_, index) => ages[index] ?? '');
}

/** The body of a search: only what decides the offers. A dorm line sends its beds and no children. */
export function searchLines(
    lines: BookLine[],
    isBed: (roomTypeId: string) => boolean,
) {
    return lines.map((line) =>
        isBed(line.room_type_id)
            ? {
                  room_type_id: Number(line.room_type_id),
                  adults: 1,
                  child_ages: [] as (number | null)[],
                  beds: Number.parseInt(line.beds, 10) || 1,
              }
            : {
                  room_type_id: Number(line.room_type_id),
                  adults: Number.parseInt(line.adults, 10) || 0,
                  child_ages: line.child_ages.map((age) =>
                      age === '' ? null : Number(age),
                  ),
                  beds: 1,
              },
    );
}

/** Add whole amounts of money in cents, so the sum has no rounding noise. Returns a decimal string. */
export function sumAmounts(amounts: string[]): string {
    const cents = amounts.reduce(
        (sum, amount) => sum + Math.round(Number(amount) * 100),
        0,
    );

    return (cents / 100).toFixed(2);
}

/** `2026-10-20` minus `days`, as `Y-m-d`. For display only: the server decides the cut-off. */
export function shiftDate(date: string, days: number): string {
    const [year, month, day] = date.split('-').map(Number);
    const shifted = new Date(year, month - 1, day + days);

    return [
        shifted.getFullYear(),
        String(shifted.getMonth() + 1).padStart(2, '0'),
        String(shifted.getDate()).padStart(2, '0'),
    ].join('-');
}

/** The number of nights from arrival to departure, or 0 when the dates are not a stay. */
export function nightsBetween(arrival: string, departure: string): number {
    if (arrival === '' || departure === '') {
        return 0;
    }

    const [ay, am, ad] = arrival.split('-').map(Number);
    const [dy, dm, dd] = departure.split('-').map(Number);
    const nights = Math.round(
        (new Date(dy, dm - 1, dd).getTime() -
            new Date(ay, am - 1, ad).getTime()) /
            86_400_000,
    );

    return Math.max(0, nights);
}
