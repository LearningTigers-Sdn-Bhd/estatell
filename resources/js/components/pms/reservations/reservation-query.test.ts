import { expect, test } from 'vite-plus/test';
import {
    canResetFilters,
    hasActiveFilters,
    reservationQuery,
    resetFilters,
    stayValue,
} from './reservation-query';
import type { ReservationFilters } from './reservation-query';

const clean: ReservationFilters = {
    search: '',
    status: '',
    source: '',
    stay: { mode: 'default', from: '2026-10-10', to: null },
};

test('a clean state gives an empty query', () => {
    expect(
        reservationQuery({ filters: clean, sort: 'arrival', view: 'cards' }),
    ).toEqual({});
    expect(hasActiveFilters(clean)).toBe(false);
});

test('filters, sort and view are kept in the query', () => {
    const filters: ReservationFilters = {
        ...clean,
        search: 'tan',
        status: 'confirmed',
        source: 'phone',
        stay: { mode: 'custom', from: '2026-10-12', to: null },
    };

    expect(reservationQuery({ filters, sort: '-total', view: 'list' })).toEqual(
        {
            filter: {
                search: 'tan',
                status: 'confirmed',
                source: 'phone',
                stay: '2026-10-12_',
            },
            sort: '-total',
            view: 'list',
        },
    );
    expect(hasActiveFilters(filters)).toBe(true);
});

test('stay values cover the default, all dates and both ends', () => {
    expect(stayValue(clean.stay)).toBeUndefined();
    expect(stayValue({ mode: 'all', from: null, to: null })).toBe('all');
    expect(
        stayValue({ mode: 'custom', from: '2026-10-01', to: '2026-10-31' }),
    ).toBe('2026-10-01_2026-10-31');
});

test('reset keeps the status tab and clears everything else', () => {
    const filters: ReservationFilters = {
        search: 'tan',
        status: 'in_house',
        source: 'phone',
        stay: { mode: 'all', from: null, to: null },
    };

    expect(resetFilters(filters)).toEqual({
        search: '',
        status: 'in_house',
        source: '',
        stay: { mode: 'default', from: null, to: null },
    });
});

test('reset is offered only when search, source or stay differ from the default', () => {
    expect(canResetFilters(clean)).toBe(false);
    expect(canResetFilters({ ...clean, status: 'confirmed' })).toBe(false);
    expect(canResetFilters({ ...clean, search: 'tan' })).toBe(true);
    expect(canResetFilters({ ...clean, source: 'phone' })).toBe(true);
    expect(
        canResetFilters({
            ...clean,
            stay: { mode: 'all', from: null, to: null },
        }),
    ).toBe(true);
});
