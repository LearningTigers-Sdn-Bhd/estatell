import { useHttp } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { matches as matchesRoute } from '@/routes/guests';

export type GuestMatchCriteria = {
    name?: string;
    email?: string;
    phone?: string;
    date_of_birth?: string;
    id_numbers?: string[];
    exclude_guest_id?: number;
};

type GuestMatch = App.Data.Guests.GuestMatchData;

/** Drops blank details, so an empty form asks the server nothing. */
function clean(criteria: GuestMatchCriteria): GuestMatchCriteria {
    const entries = Object.entries(criteria).filter(([key, value]) =>
        key === 'exclude_guest_id'
            ? value !== undefined
            : Array.isArray(value)
              ? value.some((item) => item.trim() !== '')
              : typeof value === 'string' && value.trim() !== '',
    );

    return Object.fromEntries(entries) as GuestMatchCriteria;
}

/**
 * Looks up possible duplicate guests for the details typed so far. The request waits for a pause in typing,
 * and a slow answer to an older request never replaces a newer one.
 */
export function useGuestMatches(criteria: GuestMatchCriteria): GuestMatch[] {
    const [matches, setMatches] = useState<GuestMatch[]>([]);
    const latest = useRef(0);
    const http = useHttp<GuestMatchCriteria, { matches: GuestMatch[] }>(
        'post',
        matchesRoute.url(),
        {},
    );
    const key = JSON.stringify(clean(criteria));
    const { submit, transform } = http;

    useEffect(() => {
        const request = ++latest.current;
        const cleaned = JSON.parse(key) as GuestMatchCriteria;
        const hasDetails = Object.keys(cleaned).some(
            (name) => name !== 'exclude_guest_id',
        );

        if (!hasDetails) {
            setMatches([]);

            return;
        }

        const timeout = setTimeout(() => {
            transform(() => cleaned);
            submit()
                .then((response) => {
                    if (request === latest.current) {
                        setMatches(response.matches);
                    }
                })
                .catch(() => undefined);
        }, 400);

        return () => clearTimeout(timeout);
    }, [key, submit, transform]);

    return matches;
}
