type Filters = App.Data.StayView.StayViewFiltersData;

export type DateWindow = { from: string; to: string };

/** The longest window the server accepts, counting both ends. */
export const MAX_WINDOW_DAYS = 30;

const DEFAULT_GROUPING: App.Enums.StayViewGrouping = 'room_group';

function addDays(date: string, days: number): string {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, day + days))
        .toISOString()
        .slice(0, 10);
}

/** How many dates the window covers, counting both ends. */
export function windowLength(window: DateWindow): number {
    const [fromYear, fromMonth, fromDay] = window.from.split('-').map(Number);
    const [toYear, toMonth, toDay] = window.to.split('-').map(Number);
    const from = Date.UTC(fromYear, fromMonth - 1, fromDay);
    const to = Date.UTC(toYear, toMonth - 1, toDay);

    return Math.round((to - from) / 86_400_000) + 1;
}

/** The window of the same length before (-1) or after (1) this one. */
export function shiftWindow(window: DateWindow, direction: 1 | -1): DateWindow {
    const length = windowLength(window);

    return {
        from: addDays(window.from, direction * length),
        to: addDays(window.to, direction * length),
    };
}

/** A window of the same length that starts today. */
export function windowFromToday(today: string, window: DateWindow): DateWindow {
    return {
        from: today,
        to: addDays(today, windowLength(window) - 1),
    };
}

export function windowContains(window: DateWindow, date: string): boolean {
    return date >= window.from && date <= window.to;
}

/**
 * The query string values for a window and its filters. A list with nothing selected, an empty search and the
 * default Group by are left out, so the URL stays short.
 */
export function viewQuery(
    window: DateWindow,
    filters: Filters,
): Record<string, string | string[]> {
    const query: Record<string, string | string[]> = {
        from: window.from,
        to: window.to,
    };

    if (filters.roomGroupIds.length > 0) {
        query['groups[]'] = filters.roomGroupIds.map(String);
    }

    if (filters.roomTypeIds.length > 0) {
        query['types[]'] = filters.roomTypeIds.map(String);
    }

    if (filters.statuses.length > 0) {
        query['statuses[]'] = filters.statuses;
    }

    if (filters.search.trim() !== '') {
        query.q = filters.search.trim();
    }

    if (filters.groupBy !== DEFAULT_GROUPING) {
        query.group_by = filters.groupBy;
    }

    return query;
}

/** The query as a `?a=b&c[]=d` string. */
export function queryString(query: Record<string, string | string[]>): string {
    const parts = Object.entries(query).flatMap(([key, value]) =>
        (Array.isArray(value) ? value : [value]).map(
            (item) => `${encodeURIComponent(key)}=${encodeURIComponent(item)}`,
        ),
    );

    return parts.length === 0 ? '' : `?${parts.join('&')}`;
}
