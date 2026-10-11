import { useHttp } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { preview as previewRoute } from '@/routes/reservations/check-out';

type Preview = App.Data.Reservations.GroupCheckOutPreviewData;

export type GroupCheckOutPayload = {
    rows: {
        unit_id: number;
        choice?: string;
        payment_method_id?: number;
        reference?: string;
        amount?: string;
    }[];
    one_payment?: {
        payer_unit_id: number;
        payment_method_id: number;
        reference?: string;
        amount: string;
    };
    settle_master: boolean;
};

/** How long the page waits after a change before it asks the server again. */
const DELAY_MS = 300;

/**
 * Asks the server for the totals of the group check-out page 300 ms after a change. The first answer comes with the
 * page. A slow answer to an older request never replaces a newer one, and a failed request keeps the last answer.
 * `current` is false while the shown answer is not for the latest choice. A new `refreshToken` asks again with the
 * same choices (after the server said a figure changed).
 */
export function useGroupCheckOutPreview(
    reservationId: number,
    initial: Preview,
    payload: GroupCheckOutPayload,
    refreshToken: number,
): { preview: Preview; current: boolean } {
    const key = JSON.stringify([payload, refreshToken]);
    const [answer, setAnswer] = useState({ key, preview: initial });
    const latest = useRef(0);
    // `useHttp` reads its data argument once, so the payload goes in through `transform`.
    const http = useHttp<GroupCheckOutPayload, Preview>(
        'post',
        previewRoute.url({ reservation: reservationId }),
        {} as GroupCheckOutPayload,
    );
    const { submit, transform } = http;

    useEffect(() => {
        if (key === answer.key) {
            latest.current++;

            return;
        }

        const request = ++latest.current;
        const timeout = setTimeout(() => {
            transform(
                () => (JSON.parse(key) as [GroupCheckOutPayload, number])[0],
            );
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
