import { useCallback, useEffect, useRef, useState } from 'react';

export type MyKadAddress = {
    line1: string;
    line2: string;
    postcode: string;
    city: string;
    state: string;
    state_code?: string;
};

export type MyKadData = {
    ic_number: string;
    formatted_ic: string;
    name: string;
    dob: string; // YYYY-MM-DD
    gender: string;
    nationality: string;
    nationality_code: string;
    address: MyKadAddress;
    photo: string | null; // data:image/jpeg;base64,...
};

export type ReaderStatus =
    | 'checking'
    | 'offline'
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
    const [error, setError] = useState<string | null>(null);
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
                const message = result.error || 'Failed to read smart card';
                setError(message);
                setStatus('error');
                return null;
            }

            const raw = result.data;
            const normalized: MyKadData = {
                ic_number: raw.ic_number || '',
                formatted_ic:
                    raw.formatted_ic ||
                    (raw.ic_number
                        ? raw.ic_number.replace(
                              /^(\d{6})(\d{2})(\d{4})$/,
                              '$1-$2-$3',
                          )
                        : ''),
                name: raw.name || '',
                dob: raw.dob || '',
                gender: raw.gender === 'female' ? 'female' : 'male',
                nationality: raw.nationality || 'MALAYSIAN',
                nationality_code: 'MY',
                address: {
                    line1: raw.address?.line1 || '',
                    line2: raw.address?.line2 || '',
                    postcode: raw.address?.postcode || '',
                    city: raw.address?.city || '',
                    state: raw.address?.state || '',
                    state_code: raw.address?.state_code || '',
                },
                photo: raw.photo || null,
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
            if (data.reader_connected) {
                setReaderName(data.reader_name || 'ACR39U');
                const present = Boolean(data.card_present);
                setCardPresent(present);
                setStatus(present ? 'card_present' : 'ready');
                setError(null);

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
                setStatus('ready');
                setError(data.message || 'No card reader connected');
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
        error,
        lastScannedData,
        isScanning,
        checkStatus,
        scan,
    };
}
