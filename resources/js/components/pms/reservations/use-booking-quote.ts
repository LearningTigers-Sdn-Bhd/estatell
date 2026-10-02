import { useHttp } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { quotePayload } from '@/components/pms/reservations/booking-lines';
import type { BookingLine } from '@/components/pms/reservations/booking-lines';
import { quote as quoteRoute } from '@/routes/reservations';

type Quote = App.Data.Reservations.BookingQuoteData;
type Payload = ReturnType<typeof quotePayload>;

/**
 * Asks the server what a line costs. The request waits for a pause in typing, answers are cached by what was asked,
 * and a slow answer to an older request never replaces a newer one. A line that cannot be quoted yet has no quote.
 */
export function useBookingQuote(
    line: BookingLine,
    arrival: string,
    departure: string,
    isBed: boolean,
    quotable: boolean,
): { quote: Quote | null; loading: boolean } {
    const [quote, setQuote] = useState<Quote | null>(null);
    const [loading, setLoading] = useState(false);
    const payload = useRef<Payload | null>(null);
    const cache = useRef(new Map<string, Quote>());
    const latest = useRef(0);
    const http = useHttp<Payload, Quote>(
        'post',
        quoteRoute.url(),
        () => payload.current as Payload,
    );
    const submit = http.submit;
    const key = quotable
        ? JSON.stringify(quotePayload(line, arrival, departure, isBed))
        : '';

    useEffect(() => {
        const request = ++latest.current;

        if (key === '') {
            setQuote(null);
            setLoading(false);

            return;
        }

        const cached = cache.current.get(key);

        if (cached) {
            setQuote(cached);
            setLoading(false);

            return;
        }

        setLoading(true);

        const timeout = setTimeout(() => {
            payload.current = JSON.parse(key) as Payload;
            submit()
                .then((response) => {
                    cache.current.set(key, response);

                    if (request === latest.current) {
                        setQuote(response);
                    }
                })
                .catch(() => {
                    if (request === latest.current) {
                        setQuote(null);
                    }
                })
                .finally(() => {
                    if (request === latest.current) {
                        setLoading(false);
                    }
                });
        }, 400);

        return () => clearTimeout(timeout);
    }, [key, submit]);

    return { quote, loading };
}
