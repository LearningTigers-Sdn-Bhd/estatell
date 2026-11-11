import { useCallback, useEffect, useState } from 'react';

export type ViewChoice = 'all' | 'grouped';

function stored(storageKey: string): ViewChoice {
    try {
        return window.localStorage.getItem(storageKey) === 'grouped'
            ? 'grouped'
            : 'all';
    } catch {
        return 'all';
    }
}

/**
 * All or Grouped on a reservation tab. It is remembered in the browser under `storageKey` and makes no request. The
 * first render always says All, the same as the server render, and the stored choice is read right after mounting,
 * so hydration never mismatches.
 */
export function useViewChoice(
    storageKey: string,
): [ViewChoice, (view: ViewChoice) => void] {
    const [view, setView] = useState<ViewChoice>('all');

    useEffect(() => {
        setView(stored(storageKey));
    }, [storageKey]);

    const change = useCallback(
        (next: ViewChoice) => {
            setView(next);

            try {
                window.localStorage.setItem(storageKey, next);
            } catch {
                // Browser storage can be blocked. The choice still holds until the page reloads.
            }
        },
        [storageKey],
    );

    return [view, change];
}
