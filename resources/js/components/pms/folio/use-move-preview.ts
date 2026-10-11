import { useHttp } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { preview as previewRoute } from '@/routes/reservations/folio/move';

type Preview = App.Data.Folio.MovePreviewData;

export type MovePayload = {
    target_folio_id: number;
    line_ids: number[];
    schedules: { unit_id: number; scope: string }[];
};

/** How long the page waits after a change before it asks the server again. */
const DELAY_MS = 300;

/**
 * Asks the server for the figures of the Move charges page 300 ms after a change. The first answer comes with the
 * page. A slow answer to an older request never replaces a newer one, and a failed request keeps the last answer.
 * `current` is false while the shown answer is not for the latest choice.
 */
export function useMovePreview(
    reservationId: number,
    initial: Preview,
    payload: MovePayload,
): { preview: Preview; current: boolean } {
    const key = JSON.stringify(payload);
    const [answer, setAnswer] = useState({ key, preview: initial });
    const latest = useRef(0);
    // `useHttp` reads its data argument once, so the payload goes in through `transform`.
    const http = useHttp<MovePayload, Preview>(
        'post',
        previewRoute.url({ reservation: reservationId }),
        {} as MovePayload,
    );
    const { submit, transform } = http;

    useEffect(() => {
        if (key === answer.key) {
            latest.current++;

            return;
        }

        const request = ++latest.current;
        const timeout = setTimeout(() => {
            transform(() => JSON.parse(key) as MovePayload);
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
