type RoomType = App.Data.Rates.RatePricingRoomTypeData;

/** Monday first. `iso` matches PHP's ISO weekday (1 = Monday). */
export const WEEKDAYS = [
    { iso: 1, label: 'Mon' },
    { iso: 2, label: 'Tue' },
    { iso: 3, label: 'Wed' },
    { iso: 4, label: 'Thu' },
    { iso: 5, label: 'Fri' },
    { iso: 6, label: 'Sat' },
    { iso: 7, label: 'Sun' },
];

/** One price line of a room type. `values` holds seven amounts, Monday first. A blank value is left unchanged. */
export type PricingRow = {
    id: string;
    /** `amount` is the plan's main price. `occupancy` is an explicit price for `adults` adults. */
    kind: 'amount' | 'occupancy' | 'hours';
    adults: number | null;
    /** Day use only: the number of hours this row prices. */
    hours: number | null;
    label: string;
    values: string[];
};

export type RoomTypeGrid = {
    roomTypeId: number;
    /** PerRoom only: show a price row for each adult count. */
    byAdults: boolean;
    rows: PricingRow[];
};

export type AutoFillOptions = {
    baseAdults: number;
    stepUp: number;
    stepDown: number;
    unit: 'amount' | 'percent';
};

/** PerGuest Auto: how the price per person changes with each adult after the first. */
export type GuestAutoFillOptions = {
    change: 'same' | 'less' | 'more';
    step: number;
    unit: 'amount' | 'percent';
};

/** Day use Auto: the first block, then the change for each extra hour, and an optional cap. */
export type DayUseAutoFillOptions = {
    firstHours: number;
    firstPrice: number;
    direction: 'more' | 'less';
    step: number;
    unit: 'amount' | 'percent';
    /** "Never more than". Null for no cap. */
    cap: number | null;
};

const blank = () => Array<string>(7).fill('');

export const hoursLabel = (hours: number) =>
    `${hours} ${hours === 1 ? 'hour' : 'hours'}`;

const adultsLabel = (adults: number) =>
    `${adults} ${adults === 1 ? 'adult' : 'adults'}`;

/**
 * The price rows a room type needs for the plan's pricing basis. Values already typed are kept.
 */
export function buildRows(
    basis: App.Enums.PricingBasis,
    roomType: RoomType,
    byAdults: boolean,
    previous: PricingRow[] = [],
    hoursRange: { min: number; max: number } | null = null,
): PricingRow[] {
    const kept = new Map(previous.map((row) => [row.id, row.values]));
    const row = (
        id: string,
        kind: PricingRow['kind'],
        adults: number | null,
        label: string,
        hours: number | null = null,
    ): PricingRow => ({
        id,
        kind,
        adults,
        hours,
        label,
        values: kept.get(id) ?? blank(),
    });
    const occupancy = (from: number) =>
        Array.from(
            { length: Math.max(0, roomType.maxAdults - from + 1) },
            (_, index) => from + index,
        ).map((adults) =>
            row(`adults-${adults}`, 'occupancy', adults, adultsLabel(adults)),
        );

    if (basis === 'day_use' && hoursRange) {
        return Array.from(
            { length: Math.max(0, hoursRange.max - hoursRange.min + 1) },
            (_, index) => hoursRange.min + index,
        ).map((hours) =>
            row(`hours-${hours}`, 'hours', null, hoursLabel(hours), hours),
        );
    }

    if (basis === 'per_guest') {
        return [row('amount', 'amount', 1, adultsLabel(1)), ...occupancy(2)];
    }

    if (basis === 'per_bed') {
        return [row('amount', 'amount', null, 'Price per bed')];
    }

    return [
        row('amount', 'amount', null, 'Room price'),
        ...(byAdults ? occupancy(1) : []),
    ];
}

const inGroup = (group: 'weekend' | 'weekday', weekend: number[]) =>
    WEEKDAYS.map((day, index) => ({
        index,
        member: weekend.includes(day.iso) === (group === 'weekend'),
    }))
        .filter((entry) => entry.member)
        .map((entry) => entry.index);

/** The value a Simple column shows: the first date of its group. */
export function simpleValue(
    row: PricingRow,
    group: 'weekend' | 'weekday',
    weekend: number[],
): string {
    return row.values[inGroup(group, weekend)[0]] ?? '';
}

export function setSimpleValue(
    row: PricingRow,
    group: 'weekend' | 'weekday',
    weekend: number[],
    value: string,
): PricingRow {
    const values = [...row.values];

    inGroup(group, weekend).forEach((index) => {
        values[index] = value;
    });

    return { ...row, values };
}

/** Simple can show the grid only when each row has one weekday value and one weekend value. */
export function fitsSimple(grids: RoomTypeGrid[], weekend: number[]): boolean {
    return grids.every((grid) =>
        grid.rows.every((row) =>
            (['weekday', 'weekend'] as const).every((group) => {
                const indexes = inGroup(group, weekend);

                return indexes.every(
                    (index) => row.values[index] === row.values[indexes[0]],
                );
            }),
        ),
    );
}

/** Move Simple values to another weekend choice, so the columns keep their meaning. */
export function remapWeekend(
    grids: RoomTypeGrid[],
    from: number[],
    to: number[],
): RoomTypeGrid[] {
    return grids.map((grid) => ({
        ...grid,
        rows: grid.rows.map((row) =>
            setSimpleValue(
                setSimpleValue(
                    row,
                    'weekend',
                    to,
                    simpleValue(row, 'weekend', from),
                ),
                'weekday',
                to,
                simpleValue(row, 'weekday', from),
            ),
        ),
    }));
}

/**
 * The row Auto starts from. On a PerRoom plan the room price stands for the room type's base occupancy.
 */
export function seedRow(
    rows: PricingRow[],
    roomType: RoomType,
    basis: App.Enums.PricingBasis,
    baseAdults: number,
): PricingRow | undefined {
    if (basis === 'per_room' && baseAdults === roomType.baseOccupancy) {
        return rows.find((row) => row.kind === 'amount');
    }

    return rows.find((row) => row.adults === baseAdults);
}

/** True when the row Auto starts from has at least one price. */
export function hasSeed(
    rows: PricingRow[],
    roomType: RoomType,
    basis: App.Enums.PricingBasis,
    baseAdults: number,
): boolean {
    return (
        seedRow(rows, roomType, basis, baseAdults)?.values.some(
            (value) => value !== '',
        ) ?? false
    );
}

const round = (value: number) => Math.max(0, Math.round(value * 100) / 100);

/**
 * Fill the adult-count rows from the base adult count: each step up adds, each step down subtracts.
 * Only the form is filled. Nothing about Auto is stored.
 */
export function autoFill(
    rows: PricingRow[],
    roomType: RoomType,
    basis: App.Enums.PricingBasis,
    options: AutoFillOptions,
): PricingRow[] {
    const ladder = rows
        .filter((row) => row.adults !== null)
        .sort((a, b) => (a.adults ?? 0) - (b.adults ?? 0));
    const next = new Map<string, string[]>(
        rows.map((row) => [row.id, [...row.values]]),
    );

    const move = (value: number, step: number, direction: 1 | -1) =>
        options.unit === 'percent'
            ? value * (1 + (direction * step) / 100)
            : value + direction * step;

    for (let index = 0; index < 7; index++) {
        const base = ladder.find((row) => row.adults === options.baseAdults);
        const seed = seedRow(rows, roomType, basis, options.baseAdults);
        const anchor = parseFloat(seed?.values[index] ?? '');

        if (!base || Number.isNaN(anchor)) {
            continue;
        }

        next.get(base.id)![index] = String(round(anchor));

        let value = anchor;

        ladder
            .filter((row) => (row.adults ?? 0) > options.baseAdults)
            .forEach((row) => {
                value = move(value, options.stepUp, 1);
                next.get(row.id)![index] = String(round(value));
            });

        value = anchor;

        ladder
            .filter((row) => (row.adults ?? 0) < options.baseAdults)
            .reverse()
            .forEach((row) => {
                value = move(value, options.stepDown, -1);
                next.get(row.id)![index] = String(round(value));
            });
    }

    return rows.map((row) => ({ ...row, values: next.get(row.id)! }));
}

/**
 * PerGuest Auto: start from the 1 adult price (per person). N adults pay N times the per person price, which moves
 * by one step for each adult after the first. The rows hold room totals. Only the form is filled.
 */
export function autoFillPerGuest(
    rows: PricingRow[],
    options: GuestAutoFillOptions,
): PricingRow[] {
    const seed = rows.find((row) => row.kind === 'amount');
    const direction =
        options.change === 'less' ? -1 : options.change === 'more' ? 1 : 0;

    return rows.map((row) => {
        if (row.kind !== 'occupancy' || row.adults === null || !seed) {
            return row;
        }

        const adults = row.adults;

        return {
            ...row,
            values: row.values.map((value, index) => {
                const perPerson = parseFloat(seed.values[index] ?? '');

                if (Number.isNaN(perPerson)) {
                    return value;
                }

                const steps = direction * (adults - 1);
                const moved =
                    options.unit === 'percent'
                        ? perPerson * (1 + (steps * options.step) / 100)
                        : perPerson + steps * options.step;

                return String(round(round(moved) * adults));
            }),
        };
    });
}

/**
 * Day use Auto: the first block gets the first price. Each extra hour moves the price by the step (a percentage
 * compounds from the hour before), and no hour goes above the cap. Every weekday column is filled. Hours below the
 * first block are left as they are. Only the form is filled.
 */
export function autoFillDayUse(
    rows: PricingRow[],
    options: DayUseAutoFillOptions,
): PricingRow[] {
    const direction = options.direction === 'more' ? 1 : -1;
    const prices = new Map<number, string>();
    let value = options.firstPrice;

    rows.filter((row) => row.kind === 'hours' && row.hours !== null)
        .sort((a, b) => (a.hours ?? 0) - (b.hours ?? 0))
        .forEach((row) => {
            const hours = row.hours ?? 0;

            if (hours < options.firstHours) {
                return;
            }

            if (hours > options.firstHours) {
                value =
                    options.unit === 'percent'
                        ? value * (1 + (direction * options.step) / 100)
                        : value + direction * options.step;
            }

            if (options.cap !== null) {
                value = Math.min(value, options.cap);
            }

            prices.set(hours, String(round(value)));
        });

    return rows.map((row) =>
        row.hours !== null && prices.has(row.hours)
            ? { ...row, values: Array<string>(7).fill(prices.get(row.hours)!) }
            : row,
    );
}

/** The share of one person in a PerGuest room total, for the hint under an adult-count price. Display only. */
export function perPersonShare(value: string, adults: number): number | null {
    const total = parseFloat(value);

    return Number.isNaN(total) || adults < 1 ? null : total / adults;
}

/** True when any price has been typed. */
export function hasValues(grids: RoomTypeGrid[]): boolean {
    return grids.some((grid) =>
        grid.rows.some((row) => row.values.some((value) => value !== '')),
    );
}

/** The request body: per room type, one entry for each weekday. Blank weekdays are ignored by the server. */
export function toPayload(grids: RoomTypeGrid[]) {
    return grids.map((grid) => {
        const hourRows = grid.rows.filter((row) => row.kind === 'hours');

        if (hourRows.length > 0) {
            return {
                room_type_id: grid.roomTypeId,
                days: WEEKDAYS.map((day, index) => ({
                    weekday: day.iso,
                    hours: hourRows
                        .filter((row) => row.values[index] !== '')
                        .map((row) => ({
                            hours: row.hours,
                            amount: row.values[index],
                        })),
                })),
            };
        }

        const amountRow = grid.rows.find((row) => row.kind === 'amount');
        const occupancyRows = grid.rows.filter(
            (row) => row.kind === 'occupancy',
        );

        return {
            room_type_id: grid.roomTypeId,
            days: WEEKDAYS.map((day, index) => ({
                weekday: day.iso,
                amount: amountRow?.values[index] ?? '',
                occupancy: occupancyRows
                    .filter((row) => row.values[index] !== '')
                    .map((row) => ({
                        adults: row.adults,
                        amount: row.values[index],
                    })),
            })),
        };
    });
}
