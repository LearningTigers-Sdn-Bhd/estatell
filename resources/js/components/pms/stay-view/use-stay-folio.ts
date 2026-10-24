import { useHttp } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type Folio = App.Data.StayView.StayFolioData;

export type StayFolioState =
    | { status: 'idle' }
    | { status: 'loading' }
    | { status: 'ready'; folio: Folio }
    | { status: 'failed' };

/**
 * Loads the newest folio lines of a reservation when the peek sheet opens, and again for another reservation. A slow
 * answer for an earlier reservation never replaces a newer one. `reload` asks again after a failure.
 */
export function useStayFolio(url: string | null): {
    state: StayFolioState;
    reload: () => void;
} {
    const http = useHttp<Record<string, never>, Folio>('get', url ?? '', {});
    const [state, setState] = useState<StayFolioState>({ status: 'idle' });
    const [attempt, setAttempt] = useState(0);

    useEffect(() => {
        if (url === null) {
            setState({ status: 'idle' });

            return;
        }

        let current = true;

        setState({ status: 'loading' });
        http.submit()
            .then((folio) => current && setState({ status: 'ready', folio }))
            .catch(() => current && setState({ status: 'failed' }));

        return () => {
            current = false;
        };
        // The request depends only on the url and the attempt.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [url, attempt]);

    return { state, reload: () => setAttempt((count) => count + 1) };
}
