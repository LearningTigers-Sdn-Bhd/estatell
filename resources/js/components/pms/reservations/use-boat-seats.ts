import { useEffect, useState } from 'react';
import type { BoatDirection } from '@/components/pms/reservations/boat-trips';
import { boatSeats } from '@/routes/reservations';

/**
 * The seats taken on each available departure of one direction for one date, and the departures that do not run on it
 * (`cancelled`, left out of the picker). Nothing loads until the date is set. It
 * loads again when the direction or date changes, and a slow answer to an older request never replaces a newer one.
 */
export function useBoatSeats(
    direction: BoatDirection,
    date: string,
): { seats: Record<string, number>; cancelled: number[]; loaded: boolean } {
    const key = date === '' ? '' : `${direction}|${date}`;
    const [answer, setAnswer] = useState<{
        key: string;
        seats: Record<string, number>;
        cancelled: number[];
    }>({ key: '', seats: {}, cancelled: [] });

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
                        cancelled: number[];
                    }>,
            )
            .then((body) =>
                setAnswer({
                    key,
                    seats: body.seats,
                    cancelled: body.cancelled ?? [],
                }),
            )
            .catch(() => undefined);

        return () => controller.abort();
    }, [key, direction, date]);

    return answer.key === key && key !== ''
        ? { seats: answer.seats, cancelled: answer.cancelled, loaded: true }
        : { seats: {}, cancelled: [], loaded: false };
}
