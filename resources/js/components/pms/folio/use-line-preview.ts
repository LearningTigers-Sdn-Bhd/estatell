import { useHttp } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * Asks a read-only preview route for the figures of a Dialog 300 ms after its body changes. A slow answer to an older
 * body never replaces a newer one, and a failed request clears the answer and gives the server's first message as
 * `failure`. `active` false asks nothing.
 */
export function useLinePreview<T>(
    url: string,
    active: boolean,
    body: Record<string, string | number[]>,
): { preview: T | null; failure: string | null } {
    const http = useHttp<Record<string, string | number[]>, T>('post', url, {});
    const [preview, setPreview] = useState<T | null>(null);
    const key = JSON.stringify(body);

    useEffect(() => {
        if (!active) {
            return;
        }

        let current = true;
        const timer = window.setTimeout(() => {
            http.transform(() => JSON.parse(key));
            http.submit()
                .then((answer) => current && setPreview(answer ?? null))
                .catch(() => current && setPreview(null));
        }, 300);

        return () => {
            current = false;
            window.clearTimeout(timer);
        };
        // The preview depends only on the url and the body.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [active, url, key]);

    const failure = Object.values(http.errors)[0] ?? null;

    return { preview: preview === null ? null : preview, failure };
}
