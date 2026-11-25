import { Head, router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
    CfdActiveOrderView,
    CfdIdleView,
    CfdSettledView,
} from '@/components/pms/pos/pos-cfd-views';
import type { CfdState } from '@/components/pms/pos/pos-cfd-views';
import { version as cfdVersion } from '@/routes/pos/cfd';

const POLL_MS = 2000;

/**
 * The customer-facing display of one register. It shows the idle welcome, the check the cashier is ringing up, the checkout
 * state with the static DuitNow QR, or the thank-you screen after settlement. It polls the register's version and reloads
 * its state only when it changes, and a same-PC terminal also pokes it over a BroadcastChannel. See
 * docs/34-native-pos/10-customer-facing-display.md.
 */
export default function PosCfd({
    registerId,
    version,
    state,
}: {
    registerId: number;
    version: string;
    state: CfdState;
}) {
    const latestVersion = useRef(version);
    const [settledExpired, setSettledExpired] = useState(false);

    useEffect(() => {
        latestVersion.current = version;
        setSettledExpired(false);
    }, [version, state.mode]);

    const reload = useCallback(() => {
        router.reload({ only: ['state', 'version'] });
    }, []);

    useEffect(() => {
        const timer = window.setInterval(async () => {
            try {
                const response = await fetch(
                    cfdVersion.url({ register: registerId }),
                    {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                    },
                );

                if (!response.ok) {
                    return;
                }

                const { version: current } = (await response.json()) as {
                    version: string;
                };

                if (current !== latestVersion.current) {
                    reload();
                }
            } catch {
                // Keep polling quietly across transient Wi-Fi drops.
            }
        }, POLL_MS);

        return () => window.clearInterval(timer);
    }, [registerId, reload]);

    useEffect(() => {
        if (typeof BroadcastChannel === 'undefined') {
            return;
        }

        const channel = new BroadcastChannel(`pos-cfd-${registerId}`);
        channel.onmessage = () => reload();

        return () => channel.close();
    }, [registerId, reload]);

    const mode =
        settledExpired && state.mode === 'settled' ? 'idle' : state.mode;

    return (
        <>
            <Head title={`${state.outletName} · Customer display`} />
            {mode === 'idle' && <CfdIdleView state={state} />}
            {(mode === 'active' || mode === 'checkout') && (
                <CfdActiveOrderView state={state} />
            )}
            {mode === 'settled' && (
                <CfdSettledView
                    key={
                        state.order?.orderNumber ??
                        state.order?.settledAt ??
                        'settled'
                    }
                    state={state}
                    onExpire={() => setSettledExpired(true)}
                />
            )}
        </>
    );
}
