import { useState } from 'react';

/**
 * A key that changes each time an overlay opens. Use it as the `key` of the component that holds the form, so every
 * opening starts with a clean form and no old errors. The key stays the same while the overlay closes, so the exit
 * animation keeps the form it had.
 */
export function useFreshOnOpen(open: boolean): number {
    const [state, setState] = useState({ open, count: 0 });

    if (state.open !== open) {
        setState({ open, count: open ? state.count + 1 : state.count });
    }

    return state.count;
}
