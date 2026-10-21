import { buildCellChange, isCellFormDirty } from './cell-form';
import type { CellChange, CellFormValues, CellTarget } from './cell-form';

type Cell = App.Data.Rates.RateCalendarCellData;

/** A cell edit that is staged in the browser and not saved yet. */
export type PendingChange = {
    target: CellTarget;
    roomTypeName: string;
    planName: string;
    /** The saved values of the cell, the start of the edit. */
    initial: CellFormValues;
    current: CellFormValues;
    /** Whether the plan has a price of its own to send. */
    ownPrice: boolean;
    dayUse: boolean;
};

/** Staged edits, keyed by plan, room type and date, in the order they were first staged. */
export type PendingChanges = ReadonlyMap<string, PendingChange>;

export function pendingKey(
    target: Pick<CellTarget, 'ratePlanId' | 'roomTypeId' | 'date'>,
): string {
    return `${target.ratePlanId}|${target.roomTypeId}|${target.date}`;
}

/**
 * Stage an edit. An edit that is back to the saved values is no change, so it is removed instead.
 */
export function stagePending(
    pending: PendingChanges,
    change: PendingChange,
): PendingChanges {
    const next = new Map(pending);
    const key = pendingKey(change.target);

    if (isCellFormDirty(change.initial, change.current)) {
        next.set(key, change);
    } else {
        next.delete(key);
    }

    return next;
}

function twoDecimals(amount: string): string {
    return Number.isNaN(Number(amount)) ? amount : Number(amount).toFixed(2);
}

/**
 * The cell as it will look once the staged edit is saved. A date with no cell gets one.
 */
export function applyPending(
    cell: Cell | undefined,
    change: PendingChange | undefined,
): Cell | undefined {
    if (!change) {
        return cell;
    }

    const { current, ownPrice } = change;
    const priced = ownPrice && current.amount.trim() !== '';
    const stay = (value: string): number | null =>
        value.trim() === '' ? null : Number(value);

    return {
        date: change.target.date,
        arrivalNotAllowed: cell?.arrivalNotAllowed ?? false,
        hours: cell?.hours ?? [],
        amount: priced
            ? twoDecimals(current.amount.trim())
            : (cell?.amount ?? null),
        occupancy: priced
            ? Object.entries(current.occupancy)
                  .filter(([, amount]) => amount.trim() !== '')
                  .map(([adults, amount]) => ({
                      adults: Number(adults),
                      amount: twoDecimals(amount.trim()),
                  }))
                  .sort((left, right) => left.adults - right.adults)
            : (cell?.occupancy ?? []),
        stopSell: current.stopSell,
        closedToArrival: current.closedToArrival,
        closedToDeparture: current.closedToDeparture,
        minStay: stay(current.minStay),
        maxStay: stay(current.maxStay),
    };
}

/** The request body of `rates.calendar.changes`. The order matches the error keys `changes.N...`. */
export function buildPendingPayload(pending: PendingChanges): {
    changes: CellChange[];
} {
    return {
        changes: Array.from(pending.values(), (change) =>
            buildCellChange(
                change.target,
                change.initial,
                change.current,
                change.ownPrice,
                change.dayUse,
            ),
        ),
    };
}

/**
 * The server's field errors, each with the room type, plan and date it belongs to. An error that does not name a
 * change is shown without a label.
 */
export function pendingErrors(
    errors: Record<string, string>,
    pending: PendingChanges,
): { label: string | null; message: string }[] {
    const changes = Array.from(pending.values());

    return Object.entries(errors).map(([key, message]) => {
        const index = key.match(/^changes\.(\d+)\./)?.[1];
        const change = index === undefined ? undefined : changes[Number(index)];

        return {
            label: change
                ? `${change.roomTypeName} · ${change.planName} · ${change.target.date}`
                : null,
            message,
        };
    });
}
