import { useEffect, useRef, useSyncExternalStore } from 'react';
import { download } from '@/routes/mykad-reader';

export type MyKadData = {
    name: string;
    ic_number: string;
    formatted_ic: string;
    date_of_birth: string; // YYYY-MM-DD
    gender: string;
    nationality_code: string; // 'MY'
    address_line1: string;
    address_line2: string;
    city: string;
    state: string;
    postal_code: string;
    country_code: string; // 'MY'
    photo_base64: string | null;
};

export type ReaderStatus =
    | 'checking'
    | 'offline'
    | 'no_reader'
    | 'ready'
    | 'card_present'
    | 'card_read'
    | 'reading'
    | 'error';

const BRIDGE_URL = 'http://127.0.0.1:9292';
const POLL_INTERVAL = 2500;

/** Oldest MyKad Reader the app works with. Keep in step with the version in the estatell-mykad-reader repo. */
export const REQUIRED_BRIDGE_VERSION = '2.0.0';

const DOCS_BASE_URL =
    'https://docs.estatell.com/en/docs/guests/identity-documents';

export const MYKAD_SETUP_URL = `${DOCS_BASE_URL}/set-up-mykad-reader`;
export const MYKAD_PROBLEMS_URL = `${DOCS_BASE_URL}/mykad-reader-problems`;

export type BridgeDownload =
    | { href: string; platform: 'windows' | 'macos' }
    | { href: null; platform: 'other' };

/**
 * The MyKad Reader installer for this computer. Other systems have none.
 */
export function bridgeDownload(
    userAgent: string = typeof navigator === 'undefined'
        ? ''
        : navigator.userAgent,
): BridgeDownload {
    if (/Windows/i.test(userAgent)) {
        return { href: download.url('windows'), platform: 'windows' };
    }

    if (/Macintosh|Mac OS X/i.test(userAgent)) {
        return { href: download.url('mac'), platform: 'macos' };
    }

    return { href: null, platform: 'other' };
}

/** Words for the `code` that `/read` sends on a failure. */
const READ_ERROR_MESSAGES: Record<string, string> = {
    no_reader: 'Plug the card reader into this computer.',
    no_card: 'Insert the MyKad, chip first and face up.',
    busy: 'Still reading the last card. Try again in a moment.',
    read_failed: "The card couldn't be read. Take it out and insert it again.",
    forbidden: 'MyKad Reader refused this page.',
};

/**
 * The message for a failed read. 1.x readers send no `code`, so their `error` text is the fallback.
 */
export function readErrorMessage(code: unknown, error: unknown): string {
    if (typeof code === 'string' && code in READ_ERROR_MESSAGES) {
        return READ_ERROR_MESSAGES[code];
    }

    return typeof error === 'string' && error !== ''
        ? error
        : 'Failed to read smart card';
}

export type ReaderChip = {
    tone: 'ready' | 'busy' | 'idle' | 'warning';
    label: string;
    /** Where a click goes. `null` when the chip is not a link. */
    href: string | null;
};

/**
 * The one chip staff see. An old reader or a missing one wins over what the card is doing.
 */
export function readerChip(
    status: ReaderStatus,
    isBridgeOutdated: boolean,
    download: BridgeDownload,
): ReaderChip | null {
    if (status === 'checking') {
        return null;
    }

    if (status === 'offline') {
        return download.platform === 'other'
            ? {
                  tone: 'idle',
                  label: "MyKad Reader isn't supported on this computer",
                  href: null,
              }
            : {
                  tone: 'idle',
                  label: "MyKad Reader isn't running",
                  href: MYKAD_SETUP_URL,
              };
    }

    if (isBridgeOutdated) {
        return {
            tone: 'warning',
            label: 'Update required',
            href: download.href ?? MYKAD_SETUP_URL,
        };
    }

    if (status === 'no_reader') {
        return {
            tone: 'idle',
            label: 'Plug in card reader',
            href: MYKAD_PROBLEMS_URL,
        };
    }

    if (status === 'reading') {
        return { tone: 'busy', label: 'Reading the card…', href: null };
    }

    if (status === 'card_read') {
        return { tone: 'idle', label: 'Take the card out', href: null };
    }

    return { tone: 'ready', label: 'Ready for MyKad', href: null };
}

export function isOlderVersion(
    version: string | null,
    required: string,
): boolean {
    if (!version) {
        return true;
    }

    const current = version.split('.').map(Number);
    const target = required.split('.').map(Number);

    for (let i = 0; i < target.length; i++) {
        const difference = (current[i] ?? 0) - (target[i] ?? 0);

        if (difference !== 0) {
            return difference < 0;
        }
    }

    return false;
}

/**
 * Converts a base64 Data URL (e.g. from MyKad portrait extraction) into a standard File object
 * that can be attached to Inertia / FormData form fields like front_scan.
 */
export function base64ToFile(
    dataUrl: string,
    filename = 'mykad_portrait.jpg',
): File | null {
    try {
        const arr = dataUrl.split(',');
        const mimeMatch = arr[0].match(/:(.*?);/);
        const mime = mimeMatch ? mimeMatch[1] : 'image/jpeg';
        const bstr = atob(arr[1] ?? arr[0]);
        let n = bstr.length;
        const u8arr = new Uint8Array(n);
        while (n--) {
            u8arr[n] = bstr.charCodeAt(n);
        }
        return new File([u8arr], filename, { type: mime });
    } catch {
        return null;
    }
}

/** Who gets the next card. A dialog or sheet (`screen`) goes before the guest rows behind it (`row`). */
export type ReaderClaimPriority = 'screen' | 'row';

export type ReaderSnapshot = {
    status: ReaderStatus;
    bridgeVersion: string | null;
    error: string | null;
    errorCode: string | null;
};

type ReaderClaim = {
    onScan: (card: MyKadData) => void;
    priority: ReaderClaimPriority;
};

const INITIAL_SNAPSHOT: ReaderSnapshot = {
    status: 'checking',
    bridgeVersion: null,
    error: null,
    errorCode: null,
};

/** The card fields in the one shape the forms take. */
function normalizeCard(raw: Record<string, any>): MyKadData {
    const rawIc =
        raw.raw_ic ||
        raw.ic_number ||
        (typeof raw.government_id === 'string'
            ? raw.government_id.replace(/\D/g, '')
            : '');

    return {
        name: raw.name || '',
        ic_number: rawIc,
        formatted_ic:
            raw.government_id ||
            raw.formatted_ic ||
            (rawIc ? rawIc.replace(/^(\d{6})(\d{2})(\d{4})$/, '$1-$2-$3') : ''),
        date_of_birth: raw.date_of_birth || raw.dob || '',
        gender: raw.gender === 'female' ? 'female' : 'male',
        nationality_code: 'MY',
        address_line1:
            raw.address_line1 || raw.address?.line1 || raw.home_address || '',
        address_line2: raw.address_line2 || raw.address?.line2 || '',
        city: raw.city || raw.address?.city || '',
        state: raw.state_name || raw.state || raw.address?.state || '',
        postal_code:
            raw.postal_code || raw.postcode || raw.address?.postcode || '',
        country_code: 'MY',
        photo_base64: raw.photo_base64 || raw.photo || null,
    };
}

/**
 * One reader for the whole page: one poll, and one read for each card. A card goes to one claim only, and it must come
 * out before it is read again. A card that went in with no claim is read as soon as a claim comes.
 */
export function createMykadReader() {
    let snapshot = INITIAL_SNAPSHOT;
    let claims: ReaderClaim[] = [];
    let timer: ReturnType<typeof setInterval> | null = null;
    let generation = 0;
    let cardPresent = false;
    let cardRead = false;
    let reading = false;
    const subscribers = new Set<() => void>();

    const update = (patch: Partial<ReaderSnapshot>) => {
        const next = { ...snapshot, ...patch };

        if (
            next.status === snapshot.status &&
            next.bridgeVersion === snapshot.bridgeVersion &&
            next.error === snapshot.error &&
            next.errorCode === snapshot.errorCode
        ) {
            return;
        }

        snapshot = next;
        subscribers.forEach((subscriber) => subscriber());
    };

    const currentClaim = (): ReaderClaim | undefined => {
        const latestFirst = [...claims].reverse();

        return (
            latestFirst.find((claim) => claim.priority === 'screen') ??
            latestFirst[0]
        );
    };

    const read = async () => {
        reading = true;
        cardRead = true;
        update({ status: 'reading', error: null, errorCode: null });

        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 12000);

            const res = await fetch(`${BRIDGE_URL}/read`, {
                method: 'GET',
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            });
            clearTimeout(timeoutId);

            const result = await res.json();

            if (!result.success || !result.data) {
                update({
                    status: 'error',
                    error: readErrorMessage(result.code, result.error),
                    errorCode:
                        typeof result.code === 'string' ? result.code : null,
                });

                return;
            }

            update({ status: 'card_read', error: null, errorCode: null });
            currentClaim()?.onScan(normalizeCard(result.data));
        } catch (err: unknown) {
            update({
                status: 'error',
                error:
                    err instanceof Error && err.name === 'AbortError'
                        ? 'Card read timed out. Please reconnect card.'
                        : 'Communication with card bridge failed.',
                errorCode: null,
            });
        } finally {
            reading = false;
        }
    };

    const readIfClaimed = () => {
        if (
            cardPresent &&
            !cardRead &&
            !reading &&
            currentClaim() !== undefined
        ) {
            void read();
        }
    };

    const forgetCard = () => {
        cardPresent = false;
        cardRead = false;
    };

    const poll = async () => {
        if (reading) {
            return;
        }

        const pollGeneration = generation;

        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 2000);

            const res = await fetch(`${BRIDGE_URL}/status`, {
                method: 'GET',
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            });
            clearTimeout(timeoutId);

            const data = res.ok ? await res.json() : null;

            if (pollGeneration !== generation || reading) {
                return;
            }

            if (data === null) {
                forgetCard();
                update({ status: 'offline' });

                return;
            }

            const bridgeVersion =
                typeof data.version === 'string' ? data.version : null;

            if (!data.reader_connected) {
                forgetCard();
                update({
                    status: 'no_reader',
                    bridgeVersion,
                    error: null,
                    errorCode: null,
                });

                return;
            }

            cardPresent = Boolean(data.card_present);

            if (!cardPresent) {
                // A failed read stays on screen until the card comes out.
                forgetCard();
                update({
                    status: 'ready',
                    bridgeVersion,
                    error: null,
                    errorCode: null,
                });

                return;
            }

            update({
                status: cardRead ? 'card_read' : 'card_present',
                bridgeVersion,
            });
            readIfClaimed();
        } catch {
            if (pollGeneration === generation && !reading) {
                forgetCard();
                update({ status: 'offline' });
            }
        }
    };

    const stop = () => {
        generation += 1;

        if (timer !== null) {
            clearInterval(timer);
            timer = null;
        }

        forgetCard();
        snapshot = INITIAL_SNAPSHOT;
    };

    return {
        getSnapshot: () => snapshot,
        poll,
        subscribe: (subscriber: () => void) => {
            subscribers.add(subscriber);

            if (timer === null) {
                void poll();
                timer = setInterval(() => void poll(), POLL_INTERVAL);
            }

            return () => {
                subscribers.delete(subscriber);

                if (subscribers.size === 0) {
                    stop();
                }
            };
        },
        claim: (
            onScan: (card: MyKadData) => void,
            priority: ReaderClaimPriority,
        ) => {
            const claim = { onScan, priority };
            claims = [...claims, claim];
            readIfClaimed();

            return () => {
                claims = claims.filter((one) => one !== claim);
            };
        },
    };
}

const reader = createMykadReader();

export type UseMykadReaderOptions = {
    onScan?: (data: MyKadData) => void;
    /** False shows the status only, and this caller gets no card. */
    listening?: boolean;
    priority?: ReaderClaimPriority;
};

export function useMykadReader({
    onScan,
    listening = onScan !== undefined,
    priority = 'screen',
}: UseMykadReaderOptions = {}) {
    const snapshot = useSyncExternalStore(
        reader.subscribe,
        reader.getSnapshot,
        reader.getSnapshot,
    );
    const onScanRef = useRef(onScan);
    onScanRef.current = onScan;

    useEffect(() => {
        if (!listening) {
            return;
        }

        return reader.claim((card) => onScanRef.current?.(card), priority);
    }, [listening, priority]);

    return {
        ...snapshot,
        isBridgeOutdated:
            snapshot.status !== 'offline' &&
            snapshot.status !== 'checking' &&
            isOlderVersion(snapshot.bridgeVersion, REQUIRED_BRIDGE_VERSION),
    };
}
