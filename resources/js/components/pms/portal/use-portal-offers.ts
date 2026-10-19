import { useHttp } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { offerLines } from '@/components/pms/portal/book-lines';
import { offers as offersRoute } from '@/routes/portal/bookings';

type LineOffers = App.Data.Portal.PortalLineOffersData;
type Payload = {
    arrival_date: string;
    departure_date: string;
    lines: ReturnType<typeof offerLines>;
};

/**
 * Asks the server for the room types, prices and what is left on each line's rate plan. The request waits for a pause
 * in typing, and a slow answer to an older request never replaces a newer one. A new `refresh` number asks again for
 * the same payload, for example after the save found that a price changed.
 * `lines` is the latest answer, kept on screen while a newer one is asked; `fresh` says it answers the payload as it
 * is now. `lineLoading(index)` is true only while that line's own answer is out of date: a line's answer depends on the
 * dates and on the lines up to and including it (what is left is less what the lines above take), so adding a line at
 * the end does not make the lines before it load again. `errors` are the request's validation errors (a missing child age, a past arrival).
 */
export function usePortalOffers(
    propertyCode: string,
    payload: Payload | null,
    refresh = 0,
): {
    lines: LineOffers[] | null;
    fresh: boolean;
    loading: boolean;
    lineLoading: (index: number) => boolean;
    errors: Record<string, string | undefined>;
} {
    const [answer, setAnswer] = useState<{
        key: string;
        lineKeys: string[];
        lines: LineOffers[];
    } | null>(null);
    const [loading, setLoading] = useState(false);
    const latest = useRef(0);
    // `useHttp` reads its data argument once, as the starting data, so the payload goes in through `transform`.
    const http = useHttp<Payload, { lines: LineOffers[] }>(
        'post',
        offersRoute.url({ property: propertyCode }),
        {} as Payload,
    );
    const { submit, transform } = http;
    const key = payload === null ? '' : JSON.stringify({ payload, refresh });
    const lineKeys =
        payload === null
            ? []
            : payload.lines.map((_, index) =>
                  JSON.stringify({
                      arrival_date: payload.arrival_date,
                      departure_date: payload.departure_date,
                      lines: payload.lines.slice(0, index + 1),
                      refresh,
                  }),
              );

    useEffect(() => {
        const request = ++latest.current;

        if (key === '') {
            setLoading(false);

            return;
        }

        setLoading(true);

        const timeout = setTimeout(() => {
            const body = (JSON.parse(key) as { payload: Payload }).payload;
            const keys = lineKeys;
            transform(() => body);
            submit()
                .then((response) => {
                    if (request === latest.current) {
                        setAnswer({
                            key,
                            lineKeys: keys,
                            lines: response.lines,
                        });
                    }
                })
                .catch(() => undefined)
                .finally(() => {
                    if (request === latest.current) {
                        setLoading(false);
                    }
                });
        }, 400);

        return () => clearTimeout(timeout);
    }, [key, submit, transform]);

    return {
        lines: key === '' ? null : (answer?.lines ?? null),
        fresh: key !== '' && answer?.key === key,
        loading,
        lineLoading: (index: number) =>
            loading &&
            (answer === null || answer.lineKeys[index] !== lineKeys[index]),
        errors:
            key === ''
                ? {}
                : (http.errors as Record<string, string | undefined>),
    };
}
