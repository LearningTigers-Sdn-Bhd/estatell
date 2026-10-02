import { router, usePage } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import type { ReservationView } from './reservation-query';

const STORAGE_KEY = 'reservations.view';

function parse(value: string | null | undefined): ReservationView | null {
    return value === 'cards' || value === 'list' ? value : null;
}

function stored(): ReservationView | null {
    try {
        return parse(window.localStorage.getItem(STORAGE_KEY));
    } catch {
        return null;
    }
}

/**
 * Cards or List. The view is kept in the URL (`?view=list`) and remembered in the browser, and switching it makes no
 * request: it rewrites the URL on the client and keeps the loaded page.
 */
export function useReservationView(): [
    ReservationView,
    (view: ReservationView) => void,
] {
    const { url } = usePage();
    const [view, setView] = useState<ReservationView>(
        () =>
            parse(new URL(url, 'http://localhost').searchParams.get('view')) ??
            stored() ??
            'cards',
    );

    const change = useCallback(
        (next: ReservationView) => {
            setView(next);

            try {
                window.localStorage.setItem(STORAGE_KEY, next);
            } catch {
                // Browser storage can be blocked. The URL still keeps the view.
            }

            const target = new URL(url, 'http://localhost');

            if (next === 'list') {
                target.searchParams.set('view', 'list');
            } else {
                target.searchParams.delete('view');
            }

            router.replace({
                url: `${target.pathname}${target.search}`,
                props: (current) => current,
                preserveScroll: true,
                preserveState: true,
            });
        },
        [url],
    );

    return [view, change];
}
