import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import {
    businessDateMovedOnPage,
    deriveBusinessDateView,
} from '@/lib/business-date';
import type { BusinessDateView } from '@/lib/business-date';

const CLOCK_INTERVAL = 60_000;

/**
 * The business date state for the sidebar. The clock ticks every minute and follows the server time, so a wrong
 * device clock does not change the state. `BusinessDatePoller` keeps the prop current while an audit is due or
 * running. When the date moves on the open page, the audit also posted charges, so the page data is reloaded.
 */
export function useBusinessDate(): BusinessDateView {
    const {
        url,
        props: { businessDate },
    } = usePage();
    const serverNow = businessDate?.serverNow;
    const date = businessDate?.date ?? null;
    const [now, setNow] = useState(() =>
        serverNow ? Date.parse(serverNow) : Date.now(),
    );
    const seen = useRef({ url, date });

    useEffect(() => {
        const offset = serverNow ? Date.parse(serverNow) - Date.now() : 0;
        const tick = () => setNow(Date.now() + offset);

        tick();

        const timer = window.setInterval(tick, CLOCK_INTERVAL);

        return () => window.clearInterval(timer);
    }, [serverNow]);

    useEffect(() => {
        const moved = businessDateMovedOnPage(seen.current, { url, date });

        seen.current = { url, date };

        if (moved) {
            router.reload();
        }
    }, [url, date]);

    return deriveBusinessDateView(businessDate, now);
}
