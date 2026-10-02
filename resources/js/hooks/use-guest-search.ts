import { useEffect, useRef, useState } from 'react';
import { search } from '@/routes/guests';

type Guest = App.Data.Guests.GuestOptionData;

export const GUEST_SEARCH_MIN_LENGTH = 2;

/**
 * Searches the property's guests by name, email or phone as the user types. The server sends the first few matches.
 * The request waits for a pause in typing, answers are cached by text, and a slow answer to an older request never
 * replaces a newer one. Nothing is asked for under two characters.
 */
export function useGuestSearch(text: string): {
    results: Guest[];
    searching: boolean;
} {
    const [results, setResults] = useState<Guest[]>([]);
    const [searching, setSearching] = useState(false);
    const latest = useRef(0);
    const cache = useRef(new Map<string, Guest[]>());
    const query = text.trim();

    useEffect(() => {
        const request = ++latest.current;

        if (query.length < GUEST_SEARCH_MIN_LENGTH) {
            setResults([]);
            setSearching(false);

            return;
        }

        const cached = cache.current.get(query);

        if (cached) {
            setResults(cached);
            setSearching(false);

            return;
        }

        setSearching(true);

        const timeout = setTimeout(async () => {
            try {
                const response = await fetch(
                    search.url({}, { query: { q: query } }),
                    {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                    },
                );
                const body = (await response.json()) as { guests: Guest[] };

                cache.current.set(query, body.guests);

                if (request === latest.current) {
                    setResults(body.guests);
                }
            } catch {
                if (request === latest.current) {
                    setResults([]);
                }
            } finally {
                if (request === latest.current) {
                    setSearching(false);
                }
            }
        }, 300);

        return () => clearTimeout(timeout);
    }, [query]);

    return { results, searching };
}
