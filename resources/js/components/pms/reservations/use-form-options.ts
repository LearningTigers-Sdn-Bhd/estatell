import { router } from '@inertiajs/react';
import { useEffect } from 'react';

type OptionProp = 'addUnitOptions' | 'chargeItems' | 'chargeCodes';

/**
 * Asks the server for an optional page prop the first time an overlay opens. The prop stays on the page afterwards.
 */
export function useFormOptions(
    open: boolean,
    loaded: boolean,
    prop: OptionProp | OptionProp[],
): void {
    const only = Array.isArray(prop) ? prop : [prop];
    const key = only.join(',');

    useEffect(() => {
        if (open && !loaded) {
            router.reload({ only: key.split(',') });
        }
    }, [open, loaded, key]);
}

/** The prop keys a form submit leaves out, so the redirect back keeps the lists the overlay already has. */
export const KEEP_OPTIONS = ['addUnitOptions', 'chargeItems', 'chargeCodes'];
