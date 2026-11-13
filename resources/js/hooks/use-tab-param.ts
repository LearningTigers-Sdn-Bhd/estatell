import { useState } from 'react';

/** The active tab, kept in `?tab=` without a request. An unknown or missing value falls back to the first tab. */
export function useTabParam(
    tabs: readonly string[],
): [string, (tab: string) => void] {
    const [tab, setTab] = useState(() => {
        const requested = new URLSearchParams(window.location.search).get(
            'tab',
        );

        return requested && tabs.includes(requested) ? requested : tabs[0];
    });

    const change = (next: string) => {
        const url = new URL(window.location.href);
        url.searchParams.set('tab', next);

        setTab(next);
        window.history.replaceState(window.history.state, '', url);
    };

    return [tab, change];
}
