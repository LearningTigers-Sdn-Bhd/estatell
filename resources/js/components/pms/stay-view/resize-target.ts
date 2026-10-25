import { lastNightOf } from './project-bars';
import type { Bar } from './project-bars';

type Stay = App.Data.StayView.StayData;

/** Which edge of a stay bar is dragged: the arrival or the departure. */
export type Edge = 'left' | 'right';

/** The dates a drag of an edge gives the stay. */
export type EdgeDates = { arrivalDate: string; departureDate: string };

/** Which edges of a bar have a handle. */
export type EdgeHandles = { left: boolean; right: boolean };

/** `date` moved by `days`, as `Y-m-d`. */
export function addDays(date: string, days: number): string {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, day + days))
        .toISOString()
        .slice(0, 10);
}

/** The whole days from `from` to `to`: the nights of a stay. Display only. */
export function daysBetween(from: string, to: string): number {
    return Math.round(
        (Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) /
            86_400_000,
    );
}

/**
 * The edges of a bar that can be dragged. The departure of a Confirmed or Checked-in unit can move, the arrival of a
 * Confirmed unit only. A Package plan unit has fixed nights. An edge cut by the window has no handle, and neither has
 * the edge between two segments of a stay that changes room: only the bar that holds the arrival or the last night.
 */
export function edgeHandles(
    stay: Stay,
    bar: Pick<Bar, 'start' | 'span' | 'openStart' | 'openEnd'>,
    dates: string[],
): EdgeHandles {
    const status = stay.status.value;

    if (stay.isPackagePlan || !['confirmed', 'checked_in'].includes(status)) {
        return { left: false, right: false };
    }

    return {
        left:
            status === 'confirmed' &&
            !bar.openStart &&
            dates[bar.start] === stay.arrivalDate,
        right:
            !bar.openEnd &&
            dates[bar.start + bar.span - 1] === lastNightOf(stay.departureDate),
    };
}

/**
 * The date an edge can be dragged to, the date under the pointer kept inside what the server accepts and the window.
 * The business date, not the clock, is today. The right edge stops at the last loaded date + 1 and the left edge at
 * the first loaded date: there is no auto-scroll.
 *
 * | Edge                | At least                                                  | At most             |
 * | ------------------- | --------------------------------------------------------- | ------------------- |
 * | Right, Confirmed    | arrival + 1 and business date + 1                         | last loaded date + 1 |
 * | Right, Checked in   | business date (an early departure) and arrival + 1       | last loaded date + 1 |
 * | Left (Confirmed)    | business date and the first loaded date                   | departure - 1       |
 *
 * When no date fits, the edge keeps its date.
 */
export function clampEdgeDate({
    stay,
    edge,
    date,
    businessDate,
    firstDate,
    lastDate,
}: {
    stay: Stay;
    edge: Edge;
    date: string;
    businessDate: string;
    firstDate: string;
    lastDate: string;
}): string {
    const [min, max, current] =
        edge === 'right'
            ? [
                  maxDate(
                      addDays(stay.arrivalDate, 1),
                      stay.status.value === 'checked_in'
                          ? businessDate
                          : addDays(businessDate, 1),
                  ),
                  addDays(lastDate, 1),
                  stay.departureDate,
              ]
            : [
                  maxDate(businessDate, firstDate),
                  addDays(stay.departureDate, -1),
                  stay.arrivalDate,
              ];

    if (min > max) {
        return current;
    }

    return date < min ? min : date > max ? max : date;
}

function maxDate(a: string, b: string): string {
    return a > b ? a : b;
}

/** The stay's dates with one edge moved, or null when the edge ends where it began (a drop on the same date). */
export function resizedDates(
    stay: Stay,
    edge: Edge,
    date: string,
): EdgeDates | null {
    const dates: EdgeDates =
        edge === 'right'
            ? { arrivalDate: stay.arrivalDate, departureDate: date }
            : { arrivalDate: date, departureDate: stay.departureDate };

    return dates.arrivalDate === stay.arrivalDate &&
        dates.departureDate === stay.departureDate
        ? null
        : dates;
}

/**
 * Whether a bar can be dragged sideways. Only a Confirmed unit changes both dates, a Package plan has fixed nights
 * (`ChangeUnitDates` refuses it), and a bar cut by either edge of the window cannot be placed from what is on screen.
 */
export function canShiftDates(
    stay: Stay,
    firstDate: string,
    lastDate: string,
): boolean {
    return (
        stay.status.value === 'confirmed' &&
        !stay.isPackagePlan &&
        stay.arrivalDate >= firstDate &&
        stay.departureDate <= addDays(lastDate, 1)
    );
}

/**
 * The whole columns a Confirmed bar moves, the pointer's column change kept inside what the server accepts and the
 * window: the new arrival is not before the business date or the first loaded date, and the new departure is not
 * after the last loaded date + 1 (no auto-scroll). A stay that already begins before the business date cannot take a
 * shift that keeps it there, so it moves by at least the days to the business date or not at all. 0 when no shift fits.
 */
export function clampShift({
    stay,
    columns,
    businessDate,
    firstDate,
    lastDate,
}: {
    stay: Stay;
    columns: number;
    businessDate: string;
    firstDate: string;
    lastDate: string;
}): number {
    const earliest = businessDate > firstDate ? businessDate : firstDate;
    const min = daysBetween(stay.arrivalDate, earliest);
    const max = daysBetween(stay.departureDate, addDays(lastDate, 1));

    if (min > max || max < 0) {
        return 0;
    }

    if (min > 0) {
        return columns >= min ? Math.min(columns, max) : 0;
    }

    return Math.min(Math.max(columns, min), max);
}

/** The stay's dates moved by whole days. */
export function shiftedDates(stay: Stay, days: number): EdgeDates {
    return {
        arrivalDate: addDays(stay.arrivalDate, days),
        departureDate: addDays(stay.departureDate, days),
    };
}
