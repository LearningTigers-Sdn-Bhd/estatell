import { useHttp } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { walkInPayment } from '@/routes/reservations';

type Payment = App.Data.Reservations.WalkInPaymentData;
/** The form as JSON. The hook only passes it on, so its nested shape is not spelled out. */
type Payload = Record<string, string>;

/**
 * Asks the server what the walk-in would pay. The server books the stay as a trial and rolls it back, so the amounts
 * are the folio's own. The request waits 300 ms after the form last changed, and a slow answer to an older request never
 * replaces a newer one. `invalid` is true when the booking would be refused (the answer has `errors`, or the form did
 * not pass validation), and `problem` is then the first error, so the rail can say what to fix. A new `refresh` value asks again for the same form. A null payload (the booking is not complete) asks nothing and shows nothing.
 */
export function useWalkInPayment(
    payload: Record<string, unknown> | null,
    refresh = 0,
): {
    payment: Payment | null;
    loading: boolean;
    invalid: boolean;
    problem: string | null;
} {
    const [payment, setPayment] = useState<Payment | null>(null);
    const [loading, setLoading] = useState(false);
    const [invalid, setInvalid] = useState(false);
    const [refusal, setRefusal] = useState<string | null>(null);
    const latest = useRef(0);
    const current = useRef<Payload>({});
    // `useHttp` reads its data argument once, as the starting data, so the payload goes in through `transform`.
    const http = useHttp<Payload, Payment>('post', walkInPayment.url(), {});
    const { submit, transform, errors } = http;
    const key = payload === null ? '' : JSON.stringify(payload);
    // `refresh` changes when a save found the total changed: ask again for the same form.

    useEffect(() => {
        const request = ++latest.current;

        if (key === '') {
            setPayment(null);
            setInvalid(false);
            setLoading(false);

            return;
        }

        setLoading(true);

        const timeout = setTimeout(() => {
            current.current = JSON.parse(key) as unknown as Payload;
            transform(() => current.current);
            submit()
                .then((response) => {
                    if (request === latest.current) {
                        const refused =
                            Object.keys(response.errors ?? {}).length > 0;

                        setInvalid(refused);
                        setRefusal(firstError(response.errors));
                        setPayment(refused ? null : response);
                    }
                })
                .catch(() => {
                    if (request === latest.current) {
                        setInvalid(true);
                        setRefusal(null);
                        setPayment(null);
                    }
                })
                .finally(() => {
                    if (request === latest.current) {
                        setLoading(false);
                    }
                });
        }, 300);

        return () => clearTimeout(timeout);
    }, [key, refresh, submit, transform]);

    return {
        payment,
        loading,
        invalid,
        problem: invalid ? (refusal ?? firstError(errors)) : null,
    };
}

/** The first message of an errors bag, whether each field holds one message or a list. */
function firstError(errors: unknown): string | null {
    if (errors === null || typeof errors !== 'object') {
        return null;
    }

    const first: unknown = Object.values(errors)[0];
    const message = Array.isArray(first) ? first[0] : first;

    return typeof message === 'string' && message !== '' ? message : null;
}
