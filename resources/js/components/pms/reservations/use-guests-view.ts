import { useCallback, useEffect, useState } from 'react';

export type GuestsView = 'all' | 'grouped';

const STORAGE_KEY = 'reservations.guests-view';

function stored(): GuestsView {
    try {
        return window.localStorage.getItem(STORAGE_KEY) === 'grouped'
            ? 'grouped'
            : 'all';
    } catch {
        return 'all';
    }
}

/**
 * All or Grouped on the Guests tab. It is remembered in the browser and makes no request. The first render always says
 * All, the same as the server render, and the stored choice is read right after mounting, so hydration never
 * mismatches.
 */
export function useGuestsView(): [GuestsView, (view: GuestsView) => void] {
    const [view, setView] = useState<GuestsView>('all');

    useEffect(() => {
        setView(stored());
    }, []);

    const change = useCallback((next: GuestsView) => {
        setView(next);

        try {
            window.localStorage.setItem(STORAGE_KEY, next);
        } catch {
            // Browser storage can be blocked. The choice still holds until the page reloads.
        }
    }, []);

    return [view, change];
}
