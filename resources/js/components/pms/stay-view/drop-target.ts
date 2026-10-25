import { lastNightOf } from './project-bars';

type Stay = App.Data.StayView.StayData;
type Target = App.Data.Reservations.MoveTargetData;

/** The unit statuses a drag can move: `MoveUnit` refuses the others. */
const MOVABLE_STATUSES = ['confirmed', 'checked_in'];

/** The nights a move can start on, both ends included. */
export type MoveNights = { first: string; last: string };

/** Where a drop puts the unit: the row and the first night in it. */
export type Drop = {
    rowKey: string;
    fromNight: string;
    /** The row is of another room type, so the move is for the whole stay. */
    isRoomTypeChange: boolean;
};

/**
 * The nights a move of the stay can start on: from the business date (or the arrival, when it is later) to the last
 * night. Null when the unit cannot be moved: it is not Confirmed or Checked in, or its last night is already past.
 */
export function movableNights(
    stay: Stay,
    businessDate: string,
): MoveNights | null {
    if (!MOVABLE_STATUSES.includes(stay.status.value)) {
        return null;
    }

    const first =
        stay.arrivalDate > businessDate ? stay.arrivalDate : businessDate;
    const last = lastNightOf(stay.departureDate);

    return first <= last ? { first, last } : null;
}

/** The date column under a pointer, kept inside the dates. Left of the first column (the label) is the first column. */
export function columnAt(
    pointerX: number,
    rowLeft: number,
    labelWidth: number,
    columnWidth: number,
    columnCount: number,
): number {
    const column = Math.floor((pointerX - rowLeft - labelWidth) / columnWidth);

    return Math.min(Math.max(column, 0), columnCount - 1);
}

/** The row the stay is in on a night, or null when that night has no room or bed. */
export function rowOnNight(stay: Stay, date: string): string | null {
    return (
        stay.segments.find(
            (segment) =>
                segment.firstNight <= date && date <= segment.lastNight,
        )?.rowKey ?? null
    );
}

/**
 * What a drop on a row and date does, or null when the row does not take it. A Confirmed unit moves the whole stay:
 * the pointer column is the date shift, not the night, so the move starts on the first movable night (or on the night
 * the row is free from, when that is later and the stay splits there). A Checked-in unit starts on the night under the
 * pointer, kept inside the movable nights, so a past date means tonight. Another room type moves the whole stay, so it
 * needs the arrival to be movable and starts there. The row must be free from that night (`freeFrom`), and it must
 * not be the row the unit is already in on that night.
 */
export function dropAt({
    stay,
    nights,
    rowKey,
    date,
    targets,
}: {
    stay: Stay;
    nights: MoveNights;
    rowKey: string;
    date: string;
    targets: ReadonlyMap<string, Target>;
}): Drop | null {
    const target = targets.get(rowKey);

    if (target === undefined) {
        return null;
    }

    const isRoomTypeChange = target.roomTypeId !== stay.roomTypeId;

    if (isRoomTypeChange && nights.first !== stay.arrivalDate) {
        return null;
    }

    const fromNight = isRoomTypeChange
        ? stay.arrivalDate
        : stay.status.value === 'confirmed'
          ? maxNight(nights.first, target.freeFrom)
          : date < nights.first
            ? nights.first
            : date > nights.last
              ? nights.last
              : date;

    if (target.freeFrom > fromNight || rowOnNight(stay, fromNight) === rowKey) {
        return null;
    }

    return { rowKey, fromNight, isRoomTypeChange };
}

/**
 * Whether a row takes the stay on some night, so it is not greyed out while dragging. It tries the first night the
 * row is free and the last night: the row the unit is in still takes a drop on a night it is elsewhere.
 */
export function rowTakesDrop(
    stay: Stay,
    nights: MoveNights,
    rowKey: string,
    targets: ReadonlyMap<string, Target>,
): boolean {
    const target = targets.get(rowKey);

    return (
        target !== undefined &&
        [target.freeFrom, nights.last].some(
            (date) => dropAt({ stay, nights, rowKey, date, targets }) !== null,
        )
    );
}

function maxNight(a: string, b: string): string {
    return a > b ? a : b;
}
