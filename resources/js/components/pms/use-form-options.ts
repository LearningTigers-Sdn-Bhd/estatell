import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

type OptionProp =
    | 'addUnitOptions'
    | 'chargeItems'
    | 'chargeCodes'
    | 'folioDiscounts'
    | 'paymentOptions'
    | 'companies'
    | 'boatDepartures'
    | 'boatSetup'
    | 'manualFolios'
    | 'manualTaxes';

/**
 * Asks the server for an optional page prop each time an overlay opens, so a list changed elsewhere (a payment method
 * added in Settings, in another tab or before going Back) shows without a page reload. While the request runs, the
 * overlay keeps the list it already has; `loaded` is false only before the first answer.
 */
export function useFormOptions(
    open: boolean,
    loaded: boolean,
    prop: OptionProp | OptionProp[],
): void {
    const only = Array.isArray(prop) ? prop : [prop];
    const key = only.join(',');
    const wasOpen = useRef(false);

    useEffect(() => {
        const justOpened = open && !wasOpen.current;
        wasOpen.current = open;

        if (open && (justOpened || !loaded)) {
            router.reload({ only: key.split(',') });
        }
    }, [open, loaded, key]);
}

/** The prop keys a form submit leaves out, so the redirect back keeps the lists the overlay already has. */
export const KEEP_OPTIONS = [
    'addUnitOptions',
    'chargeItems',
    'chargeCodes',
    'folioDiscounts',
    'paymentOptions',
    'companies',
    'boatDepartures',
    'boatSetup',
    'manualFolios',
    'manualTaxes',
];
