import { useState } from 'react';

/**
 * Keeps the last value that was not null. A page that opens an overlay for a row (`row !== null`) passes the row here,
 * so the overlay keeps its content while it closes and the exit animation is not cut short.
 */
export function useRetainedValue<T>(value: T | null): T | null {
    const [kept, setKept] = useState(value);

    if (value !== null && value !== kept) {
        setKept(value);
    }

    return value ?? kept;
}
