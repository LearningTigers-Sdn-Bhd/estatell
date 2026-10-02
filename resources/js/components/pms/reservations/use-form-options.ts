import { router } from '@inertiajs/react';
import { useEffect } from 'react';

/**
 * Asks the server for an optional page prop the first time an overlay opens. The prop stays on the page afterwards.
 */
export function useFormOptions(
    open: boolean,
    loaded: boolean,
    prop: 'addUnitOptions' | 'chargeItems',
): void {
    useEffect(() => {
        if (open && !loaded) {
            router.reload({ only: [prop] });
        }
    }, [open, loaded, prop]);
}

/** The prop keys a form submit leaves out, so the redirect back keeps the lists the overlay already has. */
export const KEEP_OPTIONS = ['addUnitOptions', 'chargeItems'];
