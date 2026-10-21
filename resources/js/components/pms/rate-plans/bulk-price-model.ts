import { buildRows, hasValues, toPayload, WEEKDAYS } from './pricing-state';
import type { PricingRow, RoomTypeGrid } from './pricing-state';

type Plan = App.Data.Rates.RateBulkPlanData;

/** Every date the same, a weekday price and a weekend price, or one price for each day of the week. */
export type BulkView = 'every-day' | 'simple' | 'by-day';

/** What Set all does to the prices: set them, or move the saved prices by a percent or an amount. */
export type SetAllMode = 'price' | 'percent' | 'amount';

/** One price line of a plan and room type. A pair with no prices of its own can only take restrictions. */
export type PairGrid = RoomTypeGrid & {
    ratePlanId: number;
    /** False for a derived plan and a package: the price cannot be changed here. */
    priceable: boolean;
};

/** A price column. `indexes` are the positions in a row's seven values (Monday first) the column stands for. */
export type BulkColumn = { id: string; label: string; indexes: number[] };

/** The saved price of each plan and room type, keyed `{plan id}|{room type id}`, then ISO weekday. */
export type SavedPrices = Record<string, Record<string, string | null>>;

export const pairKey = (ratePlanId: number, roomTypeId: number): string =>
    `${ratePlanId}|${roomTypeId}`;

const ALL_INDEXES = WEEKDAYS.map((_, index) => index);

export function bulkColumns(view: BulkView, weekend: number[]): BulkColumn[] {
    if (view === 'every-day') {
        return [{ id: 'all', label: 'Every day', indexes: ALL_INDEXES }];
    }

    if (view === 'simple') {
        const isWeekend = (index: number) =>
            weekend.includes(WEEKDAYS[index].iso);

        return [
            {
                id: 'weekday',
                label: 'Weekdays',
                indexes: ALL_INDEXES.filter((index) => !isWeekend(index)),
            },
            {
                id: 'weekend',
                label: 'Weekend',
                indexes: ALL_INDEXES.filter(isWeekend),
            },
        ];
    }

    return WEEKDAYS.map((day, index) => ({
        id: String(day.iso),
        label: day.label,
        indexes: [index],
    }));
}

/** The value a column shows: the first date it stands for. */
export function columnValue(row: PricingRow, column: BulkColumn): string {
    return row.values[column.indexes[0]] ?? '';
}

export function setColumnValue(
    row: PricingRow,
    column: BulkColumn,
    value: string,
): PricingRow {
    const values = [...row.values];

    column.indexes.forEach((index) => {
        values[index] = value;
    });

    return { ...row, values };
}

/** A view can show the grids only when every row has one value for each of its columns. */
export function fitsColumns(grids: PairGrid[], columns: BulkColumn[]): boolean {
    return grids.every((grid) =>
        grid.rows.every((row) =>
            columns.every((column) =>
                column.indexes.every(
                    (index) =>
                        row.values[index] === row.values[column.indexes[0]],
                ),
            ),
        ),
    );
}

/** Keep what is typed when the weekend choice changes in the weekday and weekend view. */
export function remapColumns(
    grids: PairGrid[],
    from: BulkColumn[],
    to: BulkColumn[],
): PairGrid[] {
    return grids.map((grid) => ({
        ...grid,
        rows: grid.rows.map((row) =>
            to.reduce((next, column, position) => {
                const source = from[position];

                return source
                    ? setColumnValue(next, column, columnValue(row, source))
                    : next;
            }, row),
        ),
    }));
}

/**
 * The grids of the picked plans and room types: one for each plan and room type the plan sells. Values already typed
 * are kept, and so are the prices-by-adults choices.
 */
export function buildPairGrids(
    plans: Plan[],
    excludedRoomTypeIds: number[],
    previous: PairGrid[] = [],
): PairGrid[] {
    const kept = new Map(
        previous.map((grid) => [
            pairKey(grid.ratePlanId, grid.roomTypeId),
            grid,
        ]),
    );

    return plans.flatMap((plan) =>
        plan.roomTypes
            .filter((roomType) => !excludedRoomTypeIds.includes(roomType.id))
            .map((roomType): PairGrid => {
                const before = kept.get(pairKey(plan.id, roomType.id));

                return (
                    before ?? {
                        ratePlanId: plan.id,
                        roomTypeId: roomType.id,
                        priceable: plan.hasOwnPrices,
                        byAdults: false,
                        rows: plan.hasOwnPrices
                            ? buildRows(
                                  plan.pricingBasis,
                                  {
                                      ...roomType,
                                      pricedUntil: null,
                                      ending: false,
                                  },
                                  false,
                              )
                            : [],
                    }
                );
            }),
    );
}

const round = (value: number) => Math.max(0, Math.round(value * 100) / 100);

/** The saved price a column starts from: that of its first weekday. Null when there is none. */
export function savedPrice(
    saved: SavedPrices,
    grid: Pick<PairGrid, 'ratePlanId' | 'roomTypeId'>,
    column: BulkColumn,
): string | null {
    return (
        saved[pairKey(grid.ratePlanId, grid.roomTypeId)]?.[
            String(WEEKDAYS[column.indexes[0]].iso)
        ] ?? null
    );
}

/**
 * Set all: fill the main price of every row that can take one. A change by a percent or an amount starts from the
 * saved price of each column, and a column with no saved price is left alone. Only the form is filled.
 */
export function applySetAll(
    grids: PairGrid[],
    columns: BulkColumn[],
    mode: SetAllMode,
    input: string,
    saved: SavedPrices,
): PairGrid[] {
    const number = parseFloat(input);

    if (input.trim() === '' || Number.isNaN(number)) {
        return grids;
    }

    return grids.map((grid) => {
        if (!grid.priceable) {
            return grid;
        }

        return {
            ...grid,
            rows: grid.rows.map((row) => {
                if (row.kind !== 'amount') {
                    return row;
                }

                return columns.reduce((next, column) => {
                    if (mode === 'price') {
                        return setColumnValue(next, column, input.trim());
                    }

                    const base = parseFloat(
                        savedPrice(saved, grid, column) ?? '',
                    );

                    if (Number.isNaN(base)) {
                        return next;
                    }

                    const moved =
                        mode === 'percent'
                            ? base * (1 + number / 100)
                            : base + number;

                    return setColumnValue(next, column, String(round(moved)));
                }, row);
            }),
        };
    });
}

/** True when any price has been typed. */
export function hasPairValues(grids: PairGrid[]): boolean {
    return hasValues(grids);
}

/**
 * The request part: each plan with its room types and weekday prices. A plan with no price of its own sends its room
 * types with no prices, so only the restrictions apply to them.
 */
export function toBulkPayload(grids: PairGrid[]) {
    const planIds = [...new Set(grids.map((grid) => grid.ratePlanId))];

    return planIds.map((ratePlanId) => ({
        rate_plan_id: ratePlanId,
        room_types: toPayload(
            grids.filter((grid) => grid.ratePlanId === ratePlanId),
        ),
    }));
}

/**
 * The plan picker's next selection. A Day use plan is edited on its own: picking one drops the others, and picking
 * another plan drops the Day use plan.
 */
export function nextPlanSelection(
    plans: Plan[],
    current: string[],
    next: string[],
): string[] {
    const isDayUse = (id: string) =>
        plans.find((plan) => String(plan.id) === id)?.pricingBasis ===
        'day_use';
    const added = next.filter((id) => !current.includes(id));

    if (added.some(isDayUse)) {
        return [added.find(isDayUse)!];
    }

    return next.filter((id) => !isDayUse(id));
}

/** The ranges the bulk edit can fill in from today, in months. */
export const AUTO_FILL_RANGES = [
    { months: 1, label: 'Next month' },
    { months: 3, label: 'Next 3 months' },
    { months: 6, label: 'Next 6 months' },
    { months: 12, label: 'Next 12 months' },
];

/** The last date of a range of whole months that starts on `start` (`Y-m-d`), for example 2026-10-01 for 12 months ends 2027-09-30. */
export function monthsEnd(start: string, months: number): string {
    const [year, month, day] = start.split('-').map(Number);
    const end = new Date(year, month - 1 + months, day - 1);
    const pad = (value: number) => String(value).padStart(2, '0');

    return `${end.getFullYear()}-${pad(end.getMonth() + 1)}-${pad(end.getDate())}`;
}
