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

/** `2026-10` moved by whole months, for the previous and next links. */
export function shiftMonth(month: string, delta: number): string {
    const [year, monthNumber] = month.split('-').map(Number);
    const shifted = new Date(year, monthNumber - 1 + delta, 1);

    return `${shifted.getFullYear()}-${String(shifted.getMonth() + 1).padStart(2, '0')}`;
}

/** `2026-10` as "October 2026". */
export function monthLabel(month: string): string {
    const [year, monthNumber] = month.split('-').map(Number);

    return new Date(year, monthNumber - 1, 1).toLocaleDateString('en-GB', {
        month: 'long',
        year: 'numeric',
    });
}
