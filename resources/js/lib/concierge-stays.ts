/**
 * The stays this phone has opened at one property, so a family sharing a phone can reopen any of their rooms from the
 * lobby. It lives in localStorage only and is never sent anywhere.
 */
export type SavedStay = {
    token: string;
    label: string;
    room: string | null;
    reference?: string;
    at: number;
};

/** A saved stay is offered for as long as its link can last: until 8 days after the day it was saved. */
export const SAVED_STAY_DAYS = 8;

const MAX_STAYS = 10;

const key = (propertyCode: string) => `concierge-stays:${propertyCode}`;

/** Saved stays, newest first, without the ones older than `maxAgeDays`. Empty when storage is blocked or holds junk. */
export function readStays(
    propertyCode: string,
    maxAgeDays: number,
): SavedStay[] {
    try {
        const saved = JSON.parse(
            localStorage.getItem(key(propertyCode)) ?? '[]',
        );

        if (!Array.isArray(saved)) {
            return [];
        }

        return saved
            .filter(
                (stay): stay is SavedStay =>
                    typeof stay?.token === 'string' &&
                    typeof stay?.label === 'string' &&
                    typeof stay?.at === 'number' &&
                    Date.now() - stay.at < maxAgeDays * 86_400_000,
            )
            .sort((a, b) => b.at - a.at);
    } catch {
        return [];
    }
}

/** Adds or refreshes one stay. The room is kept once known, because the page only shows it on the key step. */
export function saveStay(
    propertyCode: string,
    stay: {
        token: string;
        guestFirstName: string;
        reference: string;
        roomNumber: string | null;
    },
    maxAgeDays: number,
): void {
    try {
        const all = readStays(propertyCode, maxAgeDays);
        const before = all.find((saved) => saved.token === stay.token);
        const room = stay.roomNumber ?? before?.room ?? null;
        const label = [
            room && `Room ${room}`,
            stay.guestFirstName,
            stay.reference,
        ]
            .filter(Boolean)
            .join(' · ');

        localStorage.setItem(
            key(propertyCode),
            JSON.stringify(
                [
                    {
                        token: stay.token,
                        label,
                        room,
                        reference: stay.reference,
                        at: Date.now(),
                    },
                    // Same booking and room under an older link is the same stay.
                    ...all.filter(
                        (saved) =>
                            saved.token !== stay.token &&
                            !(
                                room &&
                                saved.room === room &&
                                (saved.reference === undefined ||
                                    saved.reference === stay.reference)
                            ),
                    ),
                ].slice(0, MAX_STAYS),
            ),
        );
    } catch {
        // Storage can be blocked; the stay still works without it.
    }
}

/** Forgets a stay that ended at the desk or whose hold expired. */
export function removeStay(propertyCode: string, token: string): void {
    try {
        const kept = readStays(propertyCode, Number.POSITIVE_INFINITY).filter(
            (saved) => saved.token !== token,
        );

        localStorage.setItem(key(propertyCode), JSON.stringify(kept));
    } catch {
        // Nothing to forget when storage is blocked.
    }
}
