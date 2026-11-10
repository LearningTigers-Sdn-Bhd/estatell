import { useCallback, useEffect, useState } from 'react';

/** The board columns a viewer can show, hide and order. The keys match the print columns of `housekeeping-tasks`. */
export type ScreenColumnKey =
    | 'room'
    | 'status'
    | 'guest'
    | 'stay'
    | 'pax'
    | 'arrival'
    | 'departure'
    | 'assigned'
    | 'remarks';

export type ScreenColumn = { key: ScreenColumnKey; visible: boolean };

/** The board columns in their default order. Room and Status are always shown. */
export const SCREEN_COLUMNS: {
    key: ScreenColumnKey;
    label: string;
    locked?: boolean;
}[] = [
    { key: 'room', label: 'Room', locked: true },
    { key: 'status', label: 'Status', locked: true },
    { key: 'guest', label: 'Guest' },
    { key: 'stay', label: 'Reservation Status' },
    { key: 'pax', label: 'Pax' },
    { key: 'arrival', label: 'Arrival' },
    { key: 'departure', label: 'Departure' },
    { key: 'assigned', label: 'Housekeeper' },
    { key: 'remarks', label: 'Notes' },
];

const STORAGE_KEY = 'housekeeping.screen-columns';

export const DEFAULT_SCREEN_COLUMNS: ScreenColumn[] = SCREEN_COLUMNS.map(
    ({ key }) => ({ key, visible: true }),
);

/**
 * Applies a stored list to the columns in code, like the print layout: stored columns keep their order, a stale key is
 * dropped, and a new column goes right after the column before it in code. Locked columns are always shown.
 */
export function mergeScreenColumns(stored: unknown): ScreenColumn[] {
    if (!Array.isArray(stored)) {
        return DEFAULT_SCREEN_COLUMNS;
    }

    const known = new Map(SCREEN_COLUMNS.map((column) => [column.key, column]));
    const columns: ScreenColumn[] = [];

    for (const item of stored) {
        const key = (item as Partial<ScreenColumn>)?.key;
        const column = key ? known.get(key) : undefined;

        if (column && !columns.some((placed) => placed.key === column.key)) {
            columns.push({
                key: column.key,
                visible: column.locked
                    ? true
                    : (item as ScreenColumn).visible !== false,
            });
        }
    }

    SCREEN_COLUMNS.forEach((column, index) => {
        if (columns.some((placed) => placed.key === column.key)) {
            return;
        }

        const previous = SCREEN_COLUMNS[index - 1]?.key;
        const at = previous
            ? columns.findIndex((placed) => placed.key === previous) + 1
            : 0;

        columns.splice(at === 0 && previous ? columns.length : at, 0, {
            key: column.key,
            visible: true,
        });
    });

    return columns;
}

function stored(): ScreenColumn[] {
    try {
        const value = window.localStorage.getItem(STORAGE_KEY);

        return mergeScreenColumns(value === null ? null : JSON.parse(value));
    } catch {
        return DEFAULT_SCREEN_COLUMNS;
    }
}

/**
 * The viewer's own board columns, remembered in this browser only. The print and export columns are the property
 * layout and stay apart. The first render uses the defaults, and the stored choice is read right after mounting, so
 * hydration never mismatches.
 */
export function useScreenColumns(): [
    ScreenColumn[],
    (columns: ScreenColumn[]) => void,
] {
    const [columns, setColumns] = useState(DEFAULT_SCREEN_COLUMNS);

    useEffect(() => {
        setColumns(stored());
    }, []);

    const save = useCallback((next: ScreenColumn[]) => {
        const merged = mergeScreenColumns(next);

        setColumns(merged);

        try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(merged));
        } catch {
            // Browser storage can be blocked. The choice still holds until the page reloads.
        }
    }, []);

    return [columns, save];
}
