import { useCallback, useEffect, useRef, useState } from 'react';
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
    | 'reading'
    | 'error';

export type UseMykadReaderOptions = {
    bridgeUrl?: string;
    pollInterval?: number;
    enabled?: boolean;
    autoScan?: boolean;
    onScan?: (data: MyKadData) => void;
};

const DEFAULT_BRIDGE_URL = 'http://127.0.0.1:9292';
const DEFAULT_POLL_INTERVAL = 2500;

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

export function useMykadReader({
    bridgeUrl = DEFAULT_BRIDGE_URL,
    pollInterval = DEFAULT_POLL_INTERVAL,
    enabled = true,
    autoScan = true,
    onScan,
}: UseMykadReaderOptions = {}) {
    const [status, setStatus] = useState<ReaderStatus>('checking');
    const [cardPresent, setCardPresent] = useState(false);
    const [readerName, setReaderName] = useState<string | null>(null);
    const [bridgeVersion, setBridgeVersion] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [errorCode, setErrorCode] = useState<string | null>(null);
    const [lastScannedData, setLastScannedData] = useState<MyKadData | null>(
        null,
    );
    const [isScanning, setIsScanning] = useState(false);

    const isScanningRef = useRef(false);
    isScanningRef.current = isScanning;
    const wasCardPresentRef = useRef(false);
    const onScanRef = useRef(onScan);
    onScanRef.current = onScan;

    const scan = useCallback(async (): Promise<MyKadData | null> => {
        if (isScanningRef.current) return null;

        setIsScanning(true);
        setStatus('reading');
        setError(null);
        setErrorCode(null);

        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 12000);

            const res = await fetch(`${bridgeUrl}/read`, {
                method: 'GET',
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            });
            clearTimeout(timeoutId);

            const result = await res.json();

            if (!result.success || !result.data) {
                setError(readErrorMessage(result.code, result.error));
                setErrorCode(
                    typeof result.code === 'string' ? result.code : null,
                );
                setStatus('error');
                return null;
            }

            const raw = result.data;
            const dob = raw.date_of_birth || raw.dob || '';
            const rawIc =
                raw.raw_ic ||
                raw.ic_number ||
                (typeof raw.government_id === 'string'
                    ? raw.government_id.replace(/\D/g, '')
                    : '');
            const formattedIc =
                raw.government_id ||
                raw.formatted_ic ||
                (rawIc
                    ? rawIc.replace(/^(\d{6})(\d{2})(\d{4})$/, '$1-$2-$3')
                    : '');
            const line1 =
                raw.address_line1 ||
                raw.address?.line1 ||
                raw.home_address ||
                '';
            const line2 = raw.address_line2 || raw.address?.line2 || '';
            const postcode =
                raw.postal_code || raw.postcode || raw.address?.postcode || '';
            const city = raw.city || raw.address?.city || '';
            const state =
                raw.state_name || raw.state || raw.address?.state || '';
            const photo = raw.photo_base64 || raw.photo || null;

            const normalized: MyKadData = {
                name: raw.name || '',
                ic_number: rawIc,
                formatted_ic: formattedIc,
                date_of_birth: dob,
                gender: raw.gender === 'female' ? 'female' : 'male',
                nationality_code: 'MY',
                address_line1: line1,
                address_line2: line2,
                city,
                state,
                postal_code: postcode,
                country_code: 'MY',
                photo_base64: photo,
            };

            setLastScannedData(normalized);
            setStatus('card_present');
            setError(null);
            onScanRef.current?.(normalized);
            return normalized;
        } catch (err: unknown) {
            const message =
                err instanceof Error && err.name === 'AbortError'
                    ? 'Card read timed out. Please reconnect card.'
                    : 'Communication with card bridge failed.';
            setError(message);
            setStatus('error');
            return null;
        } finally {
            setIsScanning(false);
        }
    }, [bridgeUrl]);

    const checkStatus = useCallback(async () => {
        if (isScanningRef.current || !enabled) {
            return;
        }

        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 2000);

            const res = await fetch(`${bridgeUrl}/status`, {
                method: 'GET',
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            });
            clearTimeout(timeoutId);

            if (!res.ok) {
                setStatus('offline');
                setCardPresent(false);
                setReaderName(null);
                wasCardPresentRef.current = false;
                return;
            }

            const data = await res.json();
            setBridgeVersion(
                typeof data.version === 'string' ? data.version : null,
            );

            if (data.reader_connected) {
                setReaderName(data.reader_name || 'ACR39U');
                const present = Boolean(data.card_present);
                setCardPresent(present);
                setStatus(present ? 'card_present' : 'ready');

                // A failed read stays on screen until the card comes out.
                if (!present) {
                    setError(null);
                    setErrorCode(null);
                }

                // Auto-scan on card insertion if enabled
                if (autoScan && present && !wasCardPresentRef.current) {
                    wasCardPresentRef.current = true;
                    void scan();
                } else if (!present) {
                    wasCardPresentRef.current = false;
                }
            } else {
                setReaderName(null);
                setCardPresent(false);
                setStatus('no_reader');
                setError(null);
                setErrorCode(null);
                wasCardPresentRef.current = false;
            }
        } catch {
            setStatus('offline');
            setCardPresent(false);
            setReaderName(null);
            wasCardPresentRef.current = false;
        }
    }, [autoScan, bridgeUrl, enabled, scan]);

    useEffect(() => {
        if (!enabled) return;

        void checkStatus();
        const timer = setInterval(() => {
            void checkStatus();
        }, pollInterval);
        return () => clearInterval(timer);
    }, [checkStatus, enabled, pollInterval]);

    return {
        status,
        cardPresent,
        readerName,
        bridgeVersion,
        isBridgeOutdated:
            status !== 'offline' &&
            status !== 'checking' &&
            isOlderVersion(bridgeVersion, REQUIRED_BRIDGE_VERSION),
        error,
        errorCode,
        lastScannedData,
        isScanning,
        checkStatus,
        scan,
    };
}
