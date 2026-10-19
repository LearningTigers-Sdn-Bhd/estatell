import { useHttp } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { preview as previewRoute } from '@/routes/reservations/folios/manual';

type Preview = App.Data.Folio.ManualPreviewData;

export type ManualPayloadRow = {
    [key: string]: string | number | number[] | null;
};

/** How long the page waits after a change before it asks the server again. */
const DELAY_MS = 300;

/**
 * Asks the server for the answer to the manual folio rows 300 ms after a change: each row's tax lines, total and
 * refusal, and the rail figures. The first answer comes with the page. A slow answer to an older request never
 * replaces a newer one, and a failed request keeps the last answer. `current` is false while the shown answer is not
 * for the latest rows.
 */
export function useManualPreview(
    reservationId: number,
    folioId: number,
    initial: Preview,
    rows: ManualPayloadRow[],
): { preview: Preview; current: boolean } {
    const key = JSON.stringify(rows);
    const [answer, setAnswer] = useState({ key, preview: initial });
    const latest = useRef(0);
    // `useHttp` reads its data argument once, so the rows go in through `transform`.
    const http = useHttp<{ rows: ManualPayloadRow[] }, Preview>(
        'post',
        previewRoute.url({ reservation: reservationId, folio: folioId }),
        { rows: [] },
    );
    const { submit, transform } = http;

    useEffect(() => {
        if (key === answer.key) {
            latest.current++;

            return;
        }

        const request = ++latest.current;
        const timeout = setTimeout(() => {
            transform(() => ({ rows: JSON.parse(key) as ManualPayloadRow[] }));
            submit()
                .then((response) => {
                    if (request === latest.current) {
                        setAnswer({ key, preview: response });
                    }
                })
                .catch(() => undefined);
        }, DELAY_MS);

        return () => clearTimeout(timeout);
        // `answer.key` is read only to skip a request the page already has an answer for.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key, submit, transform]);

    return { preview: answer.preview, current: key === answer.key };
}
