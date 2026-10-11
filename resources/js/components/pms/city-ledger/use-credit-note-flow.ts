import { useState } from 'react';

type Allocation = App.Data.CityLedger.ArAllocationRowData;

/** The Dialog that handed over to the Reverse Dialog and opens again when it closes. */
type ReturnTo = 'credit' | 'void';

/**
 * The state of the credit note Dialog, the folio's void Dialog and the Reverse allocation Dialog they hand over to.
 * Only one is open at a time: `reverseFromCredit` and `reverseFromVoid` close their Dialog, and when the Reverse
 * Dialog closes (reversed or cancelled) the Dialog that handed over opens again. `refresh` runs first, so a page that
 * holds the invoice as an optional prop can reload it and the Dialog sees the fresh balance. `reverse` opens the
 * Reverse Dialog on its own.
 */
export function useCreditNoteFlow(
    initialOpen = false,
    refresh?: (done: () => void) => void,
) {
    const [crediting, setCrediting] = useState(initialOpen);
    const [voiding, setVoiding] = useState(false);
    const [reversing, setReversing] = useState<Allocation | null>(null);
    const [returnTo, setReturnTo] = useState<ReturnTo | null>(null);

    const reverseFrom = (dialog: ReturnTo) => (allocation: Allocation) => {
        setCrediting(false);
        setVoiding(false);
        setReturnTo(dialog);
        setReversing(allocation);
    };

    const closeReverse = () => {
        setReversing(null);

        if (returnTo === null) {
            return;
        }

        const reopen = returnTo === 'credit' ? setCrediting : setVoiding;
        setReturnTo(null);

        if (refresh) {
            refresh(() => reopen(true));
        } else {
            reopen(true);
        }
    };

    return {
        crediting,
        setCrediting,
        voiding,
        setVoiding,
        reversing,
        reverse: setReversing,
        reverseFromCredit: reverseFrom('credit'),
        reverseFromVoid: reverseFrom('void'),
        closeReverse,
    };
}
