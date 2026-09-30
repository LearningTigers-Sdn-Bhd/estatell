import type { GridRow } from '@/components/pms/grid/date-grid';

type RoomType = App.Data.Rates.RateCalendarRoomTypeData;
type Plan = App.Data.Rates.RateCalendarPlanData;
type Cell = App.Data.Rates.RateCalendarCellData;

export type CalendarFilters = {
    /** A room type id as text, or "" for all. */
    roomTypeId: string;
    /** A rate plan id as text, or "" for all. */
    ratePlanId: string;
};

export type CalendarRow =
    | (GridRow & { kind: 'group'; roomType: RoomType })
    | (GridRow & { kind: 'row'; roomType: RoomType; plan: Plan });

/**
 * The grid rows: a heading for each room type, then a row for each of its plans. A room type with no plan
 * left after filtering is left out.
 */
export function buildRows(
    roomTypes: RoomType[],
    filters: CalendarFilters,
): CalendarRow[] {
    return roomTypes.flatMap((roomType): CalendarRow[] => {
        if (
            filters.roomTypeId !== '' &&
            String(roomType.id) !== filters.roomTypeId
        ) {
            return [];
        }

        const plans = roomType.plans.filter(
            (plan) =>
                filters.ratePlanId === '' ||
                String(plan.id) === filters.ratePlanId,
        );

        if (plans.length === 0) {
            return [];
        }

        return [
            { key: `group-${roomType.id}`, kind: 'group', roomType },
            ...plans.map((plan): CalendarRow => ({
                key: `row-${roomType.id}-${plan.id}`,
                kind: 'row',
                roomType,
                plan,
            })),
        ];
    });
}

/**
 * The cells of a plan by date, so a lookup for each grid cell is cheap.
 */
export function indexCells(plan: Plan): Map<string, Cell> {
    return new Map(plan.cells.map((cell) => [cell.date, cell]));
}

/** The plans of every room type, once each, for the plan filter. */
export function planOptions(
    roomTypes: RoomType[],
): { value: string; label: string }[] {
    const seen = new Map<number, string>();

    roomTypes.forEach((roomType) =>
        roomType.plans.forEach((plan) => seen.set(plan.id, plan.name)),
    );

    return [...seen].map(([id, name]) => ({ value: String(id), label: name }));
}

export type CellFlag = 'stop-sell' | 'closed-arrival' | 'closed-departure';

/** The restriction flags of a cell in a fixed order. */
export function cellFlags(cell: Cell): CellFlag[] {
    const flags: CellFlag[] = [];

    if (cell.stopSell) {
        flags.push('stop-sell');
    }

    if (cell.closedToArrival) {
        flags.push('closed-arrival');
    }

    if (cell.closedToDeparture) {
        flags.push('closed-departure');
    }

    return flags;
}

/** The stay limits of a cell, such as "2 nights min", "5 nights max" or "2-5 nights". Null when it has none. */
export function stayLimit(cell: Cell): string | null {
    if (cell.minStay !== null && cell.maxStay !== null) {
        return `${cell.minStay}-${cell.maxStay} nights`;
    }

    if (cell.minStay !== null) {
        return `Min ${cell.minStay}`;
    }

    if (cell.maxStay !== null) {
        return `Max ${cell.maxStay}`;
    }

    return null;
}

/** A price with no trailing ".00", for example "180" or "180.50". */
export function formatAmount(amount: string | null): string {
    if (amount === null) {
        return '';
    }

    return Number(amount).toLocaleString('en-US', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    });
}

/** The longest range the server accepts, counting both ends. */
export const MAX_RANGE_DAYS = 62;

export type RangePreset = 14 | 21 | 'month';

export type DateRange = { from: string; to: string };

function parseDate(date: string): Date {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(year, month - 1, day);
}

function formatDate(date: Date): string {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}

/** A `Y-m-d` date moved by whole days, read as a calendar date so a time zone never shifts it. */
export function addDays(date: string, days: number): string {
    const value = parseDate(date);

    return formatDate(
        new Date(value.getFullYear(), value.getMonth(), value.getDate() + days),
    );
}

/** The number of dates in the range, counting both ends. */
export function rangeLength({ from, to }: DateRange): number {
    return (
        Math.round(
            (parseDate(to).getTime() - parseDate(from).getTime()) / 86_400_000,
        ) + 1
    );
}

/** Whether the range is one whole calendar month. */
export function isMonthRange({ from, to }: DateRange): boolean {
    const start = parseDate(from);

    return (
        from ===
            formatDate(new Date(start.getFullYear(), start.getMonth(), 1)) &&
        to ===
            formatDate(new Date(start.getFullYear(), start.getMonth() + 1, 0))
    );
}

/** Which preset the range is, or null for a custom range. */
export function activePreset(range: DateRange): RangePreset | null {
    if (isMonthRange(range)) {
        return 'month';
    }

    const length = rangeLength(range);

    return length === 14 || length === 21 ? length : null;
}

/** The range of a preset that starts at the date. Month is the whole month the date is in. */
export function presetRange(preset: RangePreset, from: string): DateRange {
    if (preset === 'month') {
        const start = parseDate(from);

        return {
            from: formatDate(
                new Date(start.getFullYear(), start.getMonth(), 1),
            ),
            to: formatDate(
                new Date(start.getFullYear(), start.getMonth() + 1, 0),
            ),
        };
    }

    return { from, to: addDays(from, preset - 1) };
}

/** The range one step back or forward: a month for a month range, otherwise its own length. */
export function shiftRange(range: DateRange, direction: 1 | -1): DateRange {
    if (isMonthRange(range)) {
        const start = parseDate(range.from);

        return presetRange(
            'month',
            formatDate(
                new Date(start.getFullYear(), start.getMonth() + direction, 1),
            ),
        );
    }

    const length = rangeLength(range);

    return {
        from: addDays(range.from, direction * length),
        to: addDays(range.to, direction * length),
    };
}

/** The range that starts today. A month range becomes this month, and other ranges keep their length. */
export function todayRange(today: string, range: DateRange): DateRange {
    if (isMonthRange(range)) {
        return presetRange('month', today);
    }

    return {
        from: today,
        to: addDays(today, Math.min(rangeLength(range), MAX_RANGE_DAYS) - 1),
    };
}

/** Whether the date is in the range, both ends included. */
export function rangeContains({ from, to }: DateRange, date: string): boolean {
    return date >= from && date <= to;
}
