import { router } from '@inertiajs/react';
import type { VisitOptions } from '@inertiajs/core';
import { useCallback, useEffect, useRef, useState } from 'react';

export type UnsavedChangesGuard = {
    /** The blocked destination while the dialog is open, otherwise null. */
    target: string | null;
    /** Leave for the blocked destination without saving. Also used after a successful save. */
    proceed: () => void;
    /** Close the dialog and stay on the page. */
    stay: () => void;
    /** Visit a page that keeps the form (a mode switch), without the dialog. */
    visitWithout: (url: string, options?: VisitOptions) => void;
};

/**
 * Blocks in-app navigation while a form has unsaved changes and remembers where the user was going,
 * so a dialog can offer Cancel, Discard or Save. Also asks the browser to confirm a reload or tab close.
 * Prefetches, non-GET requests and visits to the current URL are never blocked.
 */
export function useUnsavedChanges(dirty: boolean): UnsavedChangesGuard {
    const [target, setTarget] = useState<string | null>(null);
    const bypass = useRef(false);

    useEffect(() => {
        if (!dirty) {
            return;
        }

        const removeBefore = router.on('before', (event) => {
            const visit = event.detail.visit;
            const here = window.location;

            if (
                bypass.current ||
                visit.prefetch ||
                visit.method !== 'get' ||
                (visit.url.pathname === here.pathname &&
                    visit.url.search === here.search)
            ) {
                return;
            }

            setTarget(visit.url.pathname + visit.url.search);

            return false;
        });
        const warn = (event: BeforeUnloadEvent) => event.preventDefault();

        window.addEventListener('beforeunload', warn);

        return () => {
            removeBefore();
            window.removeEventListener('beforeunload', warn);
        };
    }, [dirty]);

    const proceed = useCallback(() => {
        const destination = target;

        setTarget(null);

        if (destination === null) {
            return;
        }

        bypass.current = true;
        router.visit(destination, {
            onFinish: () => {
                bypass.current = false;
            },
        });
    }, [target]);

    const stay = useCallback(() => setTarget(null), []);

    const visitWithout = useCallback((url: string, options?: VisitOptions) => {
        bypass.current = true;
        router.visit(url, {
            ...options,
            onFinish: (visit) => {
                bypass.current = false;
                options?.onFinish?.(visit);
            },
        });
    }, []);

    return { target, proceed, stay, visitWithout };
}
