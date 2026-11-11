import { useCallback, useEffect, useState } from 'react';

const STORAGE_KEY = 'reservations.summary-hidden';
const LEGACY_KEY = 'reservations.folio-summary-hidden';

function stored(storageKey: string): boolean {
    try {
        const value =
            window.localStorage.getItem(storageKey) ??
            (storageKey === STORAGE_KEY
                ? window.localStorage.getItem(LEGACY_KEY)
                : null);

        return value === '1';
    } catch {
        return false;
    }
}

/**
 * Whether the user hid the summary card. On the staff reservation page one choice serves the Folio and Guests tabs
 * (the old Folio-only key is read as a fallback); the portal booking page passes its own `storageKey`. The page applies
 * it on those tabs only, so the other tabs always show the summary. The first render always says "shown", the same as
 * the server render, and the stored choice is read right after mounting, so hydration never mismatches.
 */
export function useSummaryHidden(
    storageKey: string = STORAGE_KEY,
): [boolean, () => void] {
    const [hidden, setHidden] = useState(false);

    useEffect(() => {
        setHidden(stored(storageKey));
    }, [storageKey]);

    const toggle = useCallback(() => {
        setHidden((current) => {
            const next = !current;

            try {
                window.localStorage.setItem(storageKey, next ? '1' : '0');
            } catch {
                // Browser storage can be blocked. The choice still holds until the page reloads.
            }

            return next;
        });
    }, [storageKey]);

    return [hidden, toggle];
}
