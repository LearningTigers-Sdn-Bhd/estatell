export type ReservationView = 'cards' | 'list';

export type StayFilter = {
    mode: 'default' | 'all' | 'custom';
    from: string | null;
    to: string | null;
};

export type ReservationFilters = {
    search: string;
    status: string;
    source: string;
    stay: StayFilter;
};

export type ReservationQueryState = {
    filters: ReservationFilters;
    sort: string;
    view: ReservationView;
};

export const DEFAULT_SORT = 'arrival';

/** The `filter[stay]` value of a stay range: `from_to`, where an open end is left empty. */
export function stayValue(stay: StayFilter): string | undefined {
    if (stay.mode === 'default') {
        return undefined;
    }

    if (stay.mode === 'all') {
        return 'all';
    }

    return `${stay.from ?? ''}_${stay.to ?? ''}`;
}

/**
 * The URL query of the list for a state. Defaults are left out, so a clean URL means the default list.
 */
export function reservationQuery(state: ReservationQueryState): {
    filter?: Record<string, string>;
    sort?: string;
    view?: ReservationView;
} {
    const { filters } = state;
    const filter = Object.fromEntries(
        [
            ['search', filters.search],
            ['status', filters.status],
            ['source', filters.source],
            ['stay', stayValue(filters.stay) ?? ''],
        ].filter(([, value]) => value !== ''),
    );

    return {
        ...(Object.keys(filter).length > 0 ? { filter } : {}),
        ...(state.sort && state.sort !== DEFAULT_SORT
            ? { sort: state.sort }
            : {}),
        ...(state.view === 'list' ? { view: state.view } : {}),
    };
}

/** True when any filter differs from the default list. The status tab counts as a filter. */
export function hasActiveFilters(filters: ReservationFilters): boolean {
    return (
        filters.search !== '' ||
        filters.status !== '' ||
        filters.source !== '' ||
        filters.stay.mode !== 'default'
    );
}

/** The state after Reset: search, source and stay return to their defaults, and the tab stays. */
export function resetFilters(filters: ReservationFilters): ReservationFilters {
    return {
        search: '',
        status: filters.status,
        source: '',
        stay: { mode: 'default', from: null, to: null },
    };
}
