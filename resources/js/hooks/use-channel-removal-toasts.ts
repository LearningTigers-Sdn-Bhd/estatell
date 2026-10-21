import { router, usePage, usePoll } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { toast } from '@/components/ui/toast';

/** Results already shown, kept across page visits so each is shown once. */
const shown = new Set<string>();

/**
 * Staff archive or unlink something that is on the channel manager and it waits there first, in the background. While
 * one waits this polls the shared `channelRemovals` prop, then shows the result as a toast and reloads the page, so the
 * list shows the plan archived, or still active when an OTA still uses it. A result older than a minute that this
 * browser never saw waiting is not shown again after a hard refresh.
 */
export function useChannelRemovalToasts(): void {
    const removals = usePage().props.channelRemovals ?? [];
    const waiting = removals.some((removal) => removal.state === 'pending');
    const seenWaiting = useRef(new Set<string>());
    const { start, stop } = usePoll(
        2000,
        { only: ['channelRemovals'] },
        { autoStart: false, mode: 'rest' },
    );

    useEffect(() => {
        if (waiting) {
            start();
        } else {
            stop();
        }
    }, [waiting, start, stop]);

    useEffect(() => {
        let reload = false;

        for (const removal of removals) {
            const identity = removal.key.replace(/:\d+$/, '');

            if (removal.state === 'pending') {
                seenWaiting.current.add(identity);

                continue;
            }

            const recent =
                Date.now() - new Date(removal.updatedAt).getTime() < 60_000;
            const resultKey = `${removal.key}:${removal.updatedAt}`;

            if (
                shown.has(resultKey) ||
                !(seenWaiting.current.has(identity) || recent)
            ) {
                continue;
            }

            shown.add(resultKey);
            toast.add({
                title:
                    removal.state === 'done'
                        ? (removal.message ?? 'Done.')
                        : `${removal.name} was not archived`,
                description:
                    removal.state === 'done'
                        ? undefined
                        : (removal.message ?? undefined),
                type: removal.state === 'done' ? 'success' : 'error',
                timeout: removal.state === 'done' ? 5000 : 10000,
            });
            reload = true;
        }

        if (reload) {
            router.reload();
        }
    }, [removals]);
}
