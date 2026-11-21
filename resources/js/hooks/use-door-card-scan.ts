import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import DoorLockSettingsController from '@/actions/App/Http/Controllers/DoorLocks/DoorLockSettingsController';
import { NO_CARD, scanCardUid } from '@/lib/ecrfid';

export const AUTO_KEY = 'estatell:auto-door-cards';
const SCAN_MS = 8000;

export type ScanPhase = 'reading' | 'adding' | 'again';

function sleep(ms: number, signal: AbortSignal): Promise<void> {
    return new Promise((resolve) => {
        const timer = setTimeout(resolve, ms);

        signal.addEventListener(
            'abort',
            () => {
                clearTimeout(timer);
                resolve();
            },
            { once: true },
        );
    });
}

/**
 * While `active` and a stay waits for a card, reads the desk reader and registers the card it finds with that stay's lock. Used by
 * the Door locks page and by the watcher in the layout, so the card is added from whichever page staff are on.
 */
export function useDoorCardScan(active: boolean, stayId: number | undefined) {
    const [phase, setPhase] = useState<ScanPhase>('reading');
    const [error, setError] = useState<string | null>(null);
    const [attempt, setAttempt] = useState(0);
    const tried = useRef(new Set<string>());

    useEffect(() => {
        if (!active || stayId === undefined) {
            return;
        }

        const controller = new AbortController();

        void (async () => {
            setError(null);

            while (!controller.signal.aborted) {
                setPhase('reading');

                try {
                    const uid = await scanCardUid(
                        controller.signal,
                        undefined,
                        undefined,
                        SCAN_MS,
                    );

                    if (controller.signal.aborted) {
                        return;
                    }

                    // A card that was already tried for this stay (it failed) waits to be swapped, not retried in a loop.
                    if (tried.current.has(`${stayId}:${uid}`)) {
                        setPhase('again');
                        await sleep(2000, controller.signal);

                        continue;
                    }

                    tried.current.add(`${stayId}:${uid}`);
                    setPhase('adding');
                    router.post(
                        DoorLockSettingsController.storeCard.url(),
                        { uid, reservation_unit_id: stayId },
                        {
                            preserveScroll: true,
                            onFinish: () => setAttempt((n) => n + 1),
                        },
                    );

                    return;
                } catch (caught) {
                    if (controller.signal.aborted) {
                        return;
                    }

                    if (
                        !(caught instanceof Error) ||
                        caught.message !== NO_CARD
                    ) {
                        setError(
                            caught instanceof Error
                                ? caught.message
                                : 'The reader could not be read.',
                        );
                        await sleep(3000, controller.signal);
                    }
                }
            }
        })();

        return () => controller.abort();
    }, [active, stayId, attempt]);

    return { phase, error };
}
