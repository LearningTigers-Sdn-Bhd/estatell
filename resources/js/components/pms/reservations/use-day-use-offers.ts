import { useEffect, useState } from 'react';
import { dayUseOffers } from '@/routes/reservations';

type Offer = App.Data.Reservations.DayUseOfferData;

/**
 * The hours a day use plan sells on a date from a start time, with their prices and end times. The list loads again when
 * the room type, plan, date or start changes. Nothing loads until all four are set. A slow answer to an older request
 * never replaces a newer one.
 */
export function useDayUseOffers(
    roomTypeId: string,
    ratePlanId: string,
    date: string,
    start: string,
): { offers: Offer[]; loaded: boolean } {
    const key =
        roomTypeId && ratePlanId && date && start
            ? `${roomTypeId}|${ratePlanId}|${date}|${start}`
            : '';
    const [answer, setAnswer] = useState<{ key: string; offers: Offer[] }>({
        key: '',
        offers: [],
    });

    useEffect(() => {
        if (key === '') {
            return;
        }

        const controller = new AbortController();

        fetch(
            dayUseOffers.url(
                {},
                {
                    query: {
                        room_type_id: roomTypeId,
                        rate_plan_id: ratePlanId,
                        date,
                        start_time: start,
                    },
                },
            ),
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            },
        )
            .then((response) => response.json() as Promise<{ offers: Offer[] }>)
            .then((body) => setAnswer({ key, offers: body.offers }))
            .catch(() => undefined);

        return () => controller.abort();
    }, [key, roomTypeId, ratePlanId, date, start]);

    return answer.key === key && key !== ''
        ? { offers: answer.offers, loaded: true }
        : { offers: [], loaded: false };
}
