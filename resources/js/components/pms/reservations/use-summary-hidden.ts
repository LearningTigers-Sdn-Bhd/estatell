import { useCallback, useEffect, useState } from 'react';

const STORAGE_KEY = 'reservations.folio-summary-hidden';

function stored(): boolean {
    try {
        return window.localStorage.getItem(STORAGE_KEY) === '1';
    } catch {
        return false;
    }
}

/**
 * Whether the user hid the summary card on the Folio tab. It is remembered in the browser. The page applies it on the
 * Folio tab only, so the other tabs always show the summary. The first render always says "shown", the same as the
 * server render, and the stored choice is read right after mounting, so hydration never mismatches.
 */
export function useSummaryHidden(): [boolean, () => void] {
    const [hidden, setHidden] = useState(false);

    useEffect(() => {
        setHidden(stored());
    }, []);

    const toggle = useCallback(() => {
        setHidden((current) => {
            const next = !current;

            try {
                window.localStorage.setItem(STORAGE_KEY, next ? '1' : '0');
            } catch {
                // Browser storage can be blocked. The choice still holds until the page reloads.
            }

            return next;
        });
    }, []);

    return [hidden, toggle];
}
