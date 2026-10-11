import { useState } from 'react';

type Allocation = App.Data.CityLedger.ArAllocationRowData;

/**
 * The state of the credit note Dialog and the Reverse allocation Dialog it hands over to. Only one is open at a
 * time: `reverseFromCredit` closes the credit note Dialog, and when the Reverse Dialog closes (reversed or
 * cancelled) the credit note Dialog opens again. `refresh` runs first, so a page that holds the invoice as an
 * optional prop can reload it and the Dialog sees the fresh balance. `reverse` opens the Reverse Dialog on its own.
 */
export function useCreditNoteFlow(
    initialOpen = false,
    refresh?: (done: () => void) => void,
) {
    const [crediting, setCrediting] = useState(initialOpen);
    const [reversing, setReversing] = useState<Allocation | null>(null);
    const [returnToCredit, setReturnToCredit] = useState(false);

    const reverseFromCredit = (allocation: Allocation) => {
        setCrediting(false);
        setReturnToCredit(true);
        setReversing(allocation);
    };

    const closeReverse = () => {
        setReversing(null);

        if (!returnToCredit) {
            return;
        }

        setReturnToCredit(false);

        if (refresh) {
            refresh(() => setCrediting(true));
        } else {
            setCrediting(true);
        }
    };

    return {
        crediting,
        setCrediting,
        reversing,
        reverse: setReversing,
        reverseFromCredit,
        closeReverse,
    };
}
