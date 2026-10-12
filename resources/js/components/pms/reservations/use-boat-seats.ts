import { useEffect, useState } from 'react';
import type { BoatDirection } from '@/components/pms/reservations/boat-trips';
import { boatSeats } from '@/routes/reservations';

/**
 * The seats taken on each available departure of one direction for one date. Nothing loads until the date is set. It
 * loads again when the direction or date changes, and a slow answer to an older request never replaces a newer one.
 */
export function useBoatSeats(
    direction: BoatDirection,
    date: string,
): { seats: Record<string, number>; loaded: boolean } {
    const key = date === '' ? '' : `${direction}|${date}`;
    const [answer, setAnswer] = useState<{
        key: string;
        seats: Record<string, number>;
    }>({ key: '', seats: {} });

    useEffect(() => {
        if (key === '') {
            return;
        }

        const controller = new AbortController();

        fetch(boatSeats.url({}, { query: { direction, date } }), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then(
                (response) =>
                    response.json() as Promise<{
                        seats: Record<string, number>;
                    }>,
            )
            .then((body) => setAnswer({ key, seats: body.seats }))
            .catch(() => undefined);

        return () => controller.abort();
    }, [key, direction, date]);

    return answer.key === key && key !== ''
        ? { seats: answer.seats, loaded: true }
        : { seats: {}, loaded: false };
}
