import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import DoorLockSettingsController from '@/actions/App/Http/Controllers/DoorLocks/DoorLockSettingsController';
import { AUTO_KEY, useDoorCardScan } from '@/hooks/use-door-card-scan';
import { hidSupported, readerGranted, watchReader } from '@/lib/ecrfid';

const POLL_MS = 5000;

/**
 * Keeps "Add cards automatically" working on whatever page the desk PC is on, so nobody has to leave the Door locks page open.
 * Does nothing unless this browser has the switch on and the reader connected. While on, one small JSON call every few seconds
 * asks whether a guest waits for a card. The Door locks page runs its own copy, so this stays off there.
 */
export function DoorCardWatcher() {
    const { url, props } = usePage();
    const [ready, setReady] = useState(false);
    const [auto, setAuto] = useState(false);
    const [stayId, setStayId] = useState<number>();
    const onDoorLocks =
        url.split('?')[0] === DoorLockSettingsController.edit.url();
    const hasProperty = Boolean(props.property);
    const active = ready && auto && !onDoorLocks && hasProperty;

    useEffect(() => {
        if (!hidSupported()) {
            return;
        }

        try {
            setAuto(localStorage.getItem(AUTO_KEY) === '1');
        } catch {
            // Blocked storage: the watcher stays off.
        }

        const refresh = () => void readerGranted().then(setReady);
        refresh();

        return watchReader(refresh);
    }, [url]);

    useEffect(() => {
        if (!active) {
            setStayId(undefined);

            return;
        }

        const controller = new AbortController();
        let timer: ReturnType<typeof setTimeout>;

        const poll = async () => {
            try {
                const response = await fetch(
                    DoorLockSettingsController.waiting.url(),
                    {
                        headers: { Accept: 'application/json' },
                        signal: controller.signal,
                    },
                );

                // No access or no TTLock here (403, 404): stop asking.
                if (!response.ok) {
                    return;
                }

                const body = (await response.json()) as {
                    waitingStay: { unitId: number } | null;
                };
                setStayId(body.waitingStay?.unitId);
            } catch {
                if (controller.signal.aborted) {
                    return;
                }
            }

            timer = setTimeout(() => void poll(), POLL_MS);
        };

        void poll();

        return () => {
            controller.abort();
            clearTimeout(timer);
        };
    }, [active]);

    useDoorCardScan(active, stayId);

    return null;
}
