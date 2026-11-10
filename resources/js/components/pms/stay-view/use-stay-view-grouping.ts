import { usePage } from '@inertiajs/react';
import { useCallback, useState } from 'react';

type Filters = App.Data.StayView.StayViewFiltersData;
type Grouping = App.Enums.StayViewGrouping;

const KEY = 'estatell:stay-view:group-by';

const GROUPINGS: Grouping[] = ['none', 'room_group', 'room_type'];

function parse(value: string | null | undefined): Grouping | null {
    return GROUPINGS.find((grouping) => grouping === value) ?? null;
}

function stored(): Grouping | null {
    try {
        return parse(window.localStorage.getItem(KEY));
    } catch {
        return null;
    }
}

/**
 * The Group by to open with: a `group_by` in the URL first, so a shared link keeps its grouping, then the stored choice,
 * then the server's value (the default, Room group).
 */
export function openingGrouping(
    urlValue: string | null,
    storedValue: string | null,
    serverValue: Grouping,
): Grouping {
    return parse(urlValue) ?? parse(storedValue) ?? serverValue;
}

/**
 * The Timeline and Room View filters, with Group by remembered in this browser. One choice serves both views. Only
 * Group by is stored; the filters stay in the URL. Storage can be blocked, so a failed read or write is ignored.
 */
export function useStayViewGrouping(
    initialFilters: Filters,
): [Filters, (filters: Filters) => void] {
    const { url } = usePage();
    const [filters, setFilters] = useState<Filters>(() => ({
        ...initialFilters,
        groupBy: openingGrouping(
            new URL(url, 'http://localhost').searchParams.get('group_by'),
            stored(),
            initialFilters.groupBy,
        ),
    }));

    const change = useCallback((next: Filters) => {
        setFilters((current) => {
            if (next.groupBy !== current.groupBy) {
                try {
                    window.localStorage.setItem(KEY, next.groupBy);
                } catch {
                    // The choice lasts until the page closes.
                }
            }

            return next;
        });
    }, []);

    return [filters, change];
}
