/**
 * One line of the portal book page: a rate plan, then a room type with its rooms and the guests of each room, or a dorm
 * with its beds. Numbers are kept as text while the user types. A dorm line has `beds` and always one adult for each
 * bed; a room line has rooms, adults and one age for each child.
 */
export type BookLine = {
    key: string;
    rate_plan_id: string;
    room_type_id: string;
    rooms: string;
    adults: string;
    child_ages: string[];
    beds: string;
};

/** The lead guest of one unit. Always a new guest record. The ID is optional. */
export type BookGuest = {
    full_name: string;
    phone: string;
    id_type: string;
    id_number: string;
};

/** One unit of the booking (a room or a dorm bed), in line order, with the key its guest is kept under. */
export type UnitSlot = { key: string; lineIndex: number; label: string };

export const MAX_LINES = 20;

let lineCounter = 0;

/** A new line. It takes the rate plan of the line above, or the only plan. */
export function newLine(ratePlanId = ''): BookLine {
    lineCounter += 1;

    return {
        key: `line-${lineCounter}`,
        rate_plan_id: ratePlanId,
        room_type_id: '',
        rooms: '1',
        adults: '2',
        child_ages: [],
        beds: '1',
    };
}

export function emptyGuest(): BookGuest {
    return { full_name: '', phone: '', id_type: '', id_number: '' };
}

/** Keep the first `count` ages, and add empty ones (asked for) up to `count`. */
export function resizeAges(ages: string[], count: number): string[] {
    const size = Math.max(0, Math.min(20, count));

    return Array.from({ length: size }, (_, index) => ages[index] ?? '');
}

/** How many units a line books: its beds on a dorm, its rooms otherwise. */
export function unitCount(line: BookLine, isBed: boolean): number {
    return Math.max(
        1,
        Number.parseInt(isBed ? line.beds : line.rooms, 10) || 1,
    );
}

/**
 * The units of the lines, in order: "Deluxe King #1", "Deluxe King #2", "Dorm · Bed 1". Rooms are numbered per room
 * type across the lines, beds per dorm line. A line without a room type has no units yet.
 */
export function unitSlots(
    lines: BookLine[],
    roomTypeName: (roomTypeId: string) => string | undefined,
    isBed: (roomTypeId: string) => boolean,
): UnitSlot[] {
    const numbered = new Map<string, number>();

    return lines.flatMap((line, lineIndex) => {
        const name = roomTypeName(line.room_type_id);

        if (name === undefined) {
            return [];
        }

        const bed = isBed(line.room_type_id);

        return Array.from({ length: unitCount(line, bed) }, (_, unit) => {
            const number = (numbered.get(line.room_type_id) ?? 0) + 1;
            numbered.set(line.room_type_id, number);

            return {
                key: `${line.key}:${unit}`,
                lineIndex,
                label: bed ? `${name} · Bed ${unit + 1}` : `${name} #${number}`,
            };
        });
    });
}

/** The body of the offers request: only what decides the prices. A dorm line sends its beds and no children. */
export function offerLines(
    lines: BookLine[],
    isBed: (roomTypeId: string) => boolean,
) {
    return lines.map((line) => {
        const bed = isBed(line.room_type_id);

        return {
            rate_plan_id:
                line.rate_plan_id === '' ? null : Number(line.rate_plan_id),
            room_type_id:
                line.room_type_id === '' ? null : Number(line.room_type_id),
            rooms: bed ? 1 : unitCount(line, false),
            adults: bed ? 1 : Number.parseInt(line.adults, 10) || 1,
            child_ages: bed
                ? ([] as (number | null)[])
                : line.child_ages.map((age) =>
                      age === '' ? null : Number(age),
                  ),
            beds: bed ? unitCount(line, true) : 1,
        };
    });
}

/** Add whole amounts of money in cents, so the sum has no rounding noise. Returns a decimal string. */
export function sumAmounts(amounts: (string | null)[]): string {
    const cents = amounts.reduce(
        (sum, amount) => sum + Math.round(Number(amount ?? '0') * 100),
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
