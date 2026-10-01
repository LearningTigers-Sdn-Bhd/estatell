import { useHttp, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { deriveBusinessDateView } from '@/lib/business-date';
import type { BusinessDateView } from '@/lib/business-date';
import { show } from '@/routes/business-date';

type BusinessDate = App.Data.NightAudit.BusinessDateData | null;

const CLOCK_INTERVAL = 60_000;
const POLL_INTERVAL = 300_000;

/**
 * The business date state for the sidebar. The clock ticks every minute and follows the server time, so a wrong
 * device clock does not change the state. While an audit is due or running it asks the server every five minutes,
 * so the indicator changes when the audit finishes.
 */
export function useBusinessDate(): BusinessDateView {
    const { businessDate: shared } = usePage().props;
    const [polled, setPolled] = useState<{
        from: BusinessDate;
        value: BusinessDate;
    } | null>(null);
    const businessDate = polled?.from === shared ? polled.value : shared;
    const serverNow = businessDate?.serverNow;
    const [now, setNow] = useState(() =>
        serverNow ? Date.parse(serverNow) : Date.now(),
    );
    const http = useHttp<Record<string, never>, BusinessDate>(
        'get',
        show.url(),
        {},
    );

    useEffect(() => {
        const offset = serverNow ? Date.parse(serverNow) - Date.now() : 0;
        const tick = () => setNow(Date.now() + offset);

        tick();

        const timer = window.setInterval(tick, CLOCK_INTERVAL);

        return () => window.clearInterval(timer);
    }, [serverNow]);

    const view = deriveBusinessDateView(businessDate, now);
    const waitingForAudit =
        view.state !== 'in-sync' && view.state !== 'not-live';

    useEffect(() => {
        if (!waitingForAudit) {
            return;
        }

        const timer = window.setInterval(() => {
            http.submit()
                .then((value) => setPolled({ from: shared, value }))
                .catch(() => undefined);
        }, POLL_INTERVAL);

        return () => window.clearInterval(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [waitingForAudit, shared]);

    return view;
}
