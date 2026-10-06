import { useHttp } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * Reads what a unit action form shows (the fee, the new total) from its read-only preview route when the form opens
 * and again whenever `body` changes. `delay` waits for typing to stop. `reload` asks again, for example after the
 * server refused with `fee_changed`. The preview is text for the user: the form never sends an amount back.
 */
export function useUnitPreview<T>(
    url: string,
    active: boolean,
    body: Record<string, string> = {},
    delay = 0,
): { preview: T | null; reload: () => void } {
    const http = useHttp<Record<string, string>, T>('post', url, {});
    const [preview, setPreview] = useState<T | null>(null);
    const [attempt, setAttempt] = useState(0);
    const key = JSON.stringify(body);

    useEffect(() => {
        if (!active) {
            return;
        }

        let current = true;
        const timer = window.setTimeout(() => {
            http.transform(() => JSON.parse(key));
            http.submit()
                .then((answer) => current && setPreview(answer))
                .catch(() => current && setPreview(null));
        }, delay);

        return () => {
            current = false;
            window.clearTimeout(timer);
        };
        // The preview depends only on the url, the body and the attempt.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [active, url, key, attempt, delay]);

    return { preview, reload: () => setAttempt((count) => count + 1) };
}
