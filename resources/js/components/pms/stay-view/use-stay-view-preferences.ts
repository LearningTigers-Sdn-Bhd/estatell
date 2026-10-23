import { useCallback, useState } from 'react';
import type { RateChoice } from './nightly-rate';

const KEY = 'estatell:stay-view:timeline';

type Preferences = { showRates: boolean; rateChoice: RateChoice };

const DEFAULTS: Preferences = {
    showRates: true,
    rateChoice: { roomTypeId: null, ratePlanId: null },
};

function read(): Preferences {
    try {
        const raw = window.localStorage.getItem(KEY);

        return raw === null
            ? DEFAULTS
            : { ...DEFAULTS, ...(JSON.parse(raw) as Partial<Preferences>) };
    } catch {
        return DEFAULTS;
    }
}

/**
 * What the Timeline View remembers in this browser: whether the rate row shows, and its room type and rate plan.
 * Storage can be blocked, so a failed read or write falls back to the defaults.
 */
export function useStayViewPreferences() {
    const [preferences, setPreferences] = useState<Preferences>(read);

    const update = useCallback((change: Partial<Preferences>) => {
        setPreferences((current) => {
            const next = { ...current, ...change };

            try {
                window.localStorage.setItem(KEY, JSON.stringify(next));
            } catch {
                // The choice lasts until the page closes.
            }

            return next;
        });
    }, []);

    return [preferences, update] as const;
}
