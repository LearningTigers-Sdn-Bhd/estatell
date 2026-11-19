/**
 * Reads the UID of a card on the desk's ECRFID 13.56 MHz reader (USB HID FFFE:0091) from the browser, with WebHID.
 * Framing and inventory follow elock-dock/internal/ecrfid/reader.go. It only asks "which cards are in the field";
 * it never authenticates or writes a card. Needs Chrome or Edge over HTTPS (localhost counts).
 */

type HidReportInfo = {
    reportId?: number;
    items?: { reportSize?: number; reportCount?: number }[];
};
type HidDevice = {
    opened: boolean;
    collections?: { outputReports?: HidReportInfo[] }[];
    vendorId: number;
    productId: number;
    open(): Promise<void>;
    close(): Promise<void>;
    forget?(): Promise<void>;
    sendReport(reportId: number, data: BufferSource): Promise<void>;
    addEventListener(
        type: 'inputreport',
        listener: (event: { data: DataView }) => void,
    ): void;
    removeEventListener(
        type: 'inputreport',
        listener: (event: { data: DataView }) => void,
    ): void;
};
type Hid = {
    addEventListener(
        type: 'connect' | 'disconnect',
        listener: () => void,
    ): void;
    removeEventListener(
        type: 'connect' | 'disconnect',
        listener: () => void,
    ): void;
    getDevices(): Promise<HidDevice[]>;
    requestDevice(options: {
        filters: { vendorId: number; productId: number; usagePage: number }[];
    }): Promise<HidDevice[]>;
};

const VENDOR_ID = 0xfffe;
const PRODUCT_ID = 0x0091;
const USAGE_PAGE = 0xff00;
const INVENTORY = { type: 0xfd, code: 0x01 };

export const hidSupported = (): boolean => 'hid' in navigator;

/** The error `scanCardUid` rejects with when no card came in time, so a caller that keeps waiting can tell it from a real failure. */
export const NO_CARD = 'No card was held on the reader.';

function crc16(bytes: number[]): number {
    let crc = 0xffff;

    for (const byte of bytes) {
        crc ^= byte;

        for (let i = 0; i < 8; i++) {
            crc = crc & 1 ? (crc >> 1) ^ 0xa001 : crc >> 1;
        }
    }

    return crc;
}

function frame(type: number, code: number, data: number[]): number[] {
    const body = [6 + data.length, 0xff, type, code, ...data];
    const crc = crc16(body);

    return [0xec, ...body, crc & 0xff, crc >> 8];
}

/**
 * The UID bytes of an inventory reply, `null` for "no card yet" or a frame that is not an inventory answer. The reader uses
 * numbered reports where report ID n carries n bytes, so the browser hands over the bare frame (the ID is its length).
 */
export function parseInventoryReport(raw: number[]): number[] | null {
    // Unnumbered reports (Windows, and the vendor's own hidapi code) arrive as [length, frame..., padding].
    // Windows pads every input report to the largest report size (51 bytes), so cut the frame to its own length.
    const start = raw[0] !== 0xec && raw[1] === 0xec ? 1 : 0;
    const f = raw.slice(start, start + raw[start + 1] + 1);

    if (f.length < 8 || f[0] !== 0xec || f[1] !== f.length - 1) {
        return null;
    }

    const crc = crc16(f.slice(1, -2));

    if (crc !== (f[f.length - 2] | (f[f.length - 1] << 8))) {
        return null;
    }

    const [type, code, status] = [f[3], f[4], f[5]];
    const data = f.slice(6, -2);

    return type === INVENTORY.type &&
        code === INVENTORY.code &&
        status === 0 &&
        data.length === 4
        ? data
        : null;
}

/**
 * How to write the request. The reader's own descriptor decides: an unnumbered output report (ID 0, fixed size, the
 * layout the vendor's hidapi code uses) must be [length, frame..., zero padding]; otherwise a numbered report whose ID is
 * the frame length. Windows rejects or ignores the wrong one, macOS is forgiving.
 */
export function buildOutputReport(
    device: Pick<HidDevice, 'collections'>,
    request: number[],
): { id: number; data: Uint8Array<ArrayBuffer> } {
    const reports = (device.collections ?? []).flatMap(
        (c) => c.outputReports ?? [],
    );
    const unnumbered = reports.find((r) => !r.reportId);

    if (unnumbered || reports.length === 0) {
        const size =
            unnumbered?.items?.reduce(
                (sum, i) => sum + (i.reportSize ?? 0) * (i.reportCount ?? 0),
                0,
            ) ?? 0;
        const data = new Uint8Array(Math.max(size / 8, request.length + 1));
        data.set([request.length, ...request]);

        return { id: 0, data };
    }

    return { id: request.length, data: Uint8Array.from(request) };
}

/** Whether the browser already lets this site use the reader, so scanning needs no picker. */
export async function readerGranted(): Promise<boolean> {
    const hid = (navigator as unknown as { hid?: Hid }).hid;

    return (
        hid !== undefined &&
        (await hid.getDevices()).some(
            (d) => d.vendorId === VENDOR_ID && d.productId === PRODUCT_ID,
        )
    );
}

/** Opens the browser's reader picker. Call it from a click. Once granted, the page finds the reader again with no click. */
export async function connectReader(): Promise<void> {
    const hid = (navigator as unknown as { hid?: Hid }).hid;

    if (!hid) {
        throw new Error('Reading cards needs Chrome or Edge.');
    }

    await pickReader(hid);
}

/** Calls `listener` when a reader is plugged in or out. Returns the function that stops listening. */
export function watchReader(listener: () => void): () => void {
    const hid = (navigator as unknown as { hid?: Hid }).hid;

    hid?.addEventListener('connect', listener);
    hid?.addEventListener('disconnect', listener);

    return () => {
        hid?.removeEventListener('connect', listener);
        hid?.removeEventListener('disconnect', listener);
    };
}

async function pickReader(hid: Hid): Promise<HidDevice> {
    const known = (await hid.getDevices()).find(
        (d) => d.vendorId === VENDOR_ID && d.productId === PRODUCT_ID,
    );

    const device =
        known ??
        (
            await hid.requestDevice({
                filters: [
                    {
                        vendorId: VENDOR_ID,
                        productId: PRODUCT_ID,
                        usagePage: USAGE_PAGE,
                    },
                ],
            })
        )[0];

    if (!device) {
        throw new Error('No reader selected.');
    }

    return device;
}

/**
 * Wait for a card on the reader and resolve with its UID as hex, like `0DD25CE7`.
 * Call it from a click: the browser only shows its reader picker for a user action.
 */
export async function scanCardUid(
    signal?: AbortSignal,
    onReady?: () => void,
    onDetected?: () => void,
    timeoutMs = 30_000,
): Promise<string> {
    const hid = (navigator as unknown as { hid?: Hid }).hid;

    if (!hid) {
        throw new Error('Reading cards needs Chrome or Edge.');
    }

    const device = await pickReader(hid);

    if (signal?.aborted) {
        throw new DOMException('Cancelled', 'AbortError');
    }

    if (!device.opened) {
        try {
            await device.open();
        } catch {
            // A stale grant is the usual cause: forget it so the next try shows the picker again.
            await device.forget?.().catch(() => undefined);

            throw new Error(
                'The reader could not be opened. Close other tabs or apps that use it (elock-dock, Keyport), unplug and replug it, then try again.',
            );
        }
    }

    if (signal?.aborted) {
        await device.close().catch(() => undefined);
        throw new DOMException('Cancelled', 'AbortError');
    }

    onReady?.();

    const request = frame(INVENTORY.type, INVENTORY.code, [0]);
    const report = buildOutputReport(device, request);

    return new Promise<string>((resolve, reject) => {
        let done = false;
        const finish = (result: () => void) => {
            if (done) {
                return;
            }

            done = true;
            clearInterval(poll);
            clearTimeout(timeout);
            device.removeEventListener('inputreport', onReport);
            void device
                .close()
                .catch(() => undefined)
                .finally(result);
        };
        const onReport = (event: { data: DataView }) => {
            const uid = parseInventoryReport(
                Array.from(
                    new Uint8Array(
                        event.data.buffer,
                        event.data.byteOffset,
                        event.data.byteLength,
                    ),
                ),
            );

            if (uid) {
                onDetected?.();
                finish(() =>
                    resolve(
                        uid
                            .map((b) => b.toString(16).padStart(2, '0'))
                            .join('')
                            .toUpperCase(),
                    ),
                );
            }
        };
        const poll = setInterval(() => {
            device
                .sendReport(report.id, report.data)
                .catch((error: unknown) =>
                    finish(() =>
                        reject(
                            error instanceof Error
                                ? error
                                : new Error('The reader stopped answering.'),
                        ),
                    ),
                );
        }, 400);
        const timeout = setTimeout(
            () => finish(() => reject(new Error(NO_CARD))),
            timeoutMs,
        );

        signal?.addEventListener('abort', () =>
            finish(() => reject(new DOMException('Cancelled', 'AbortError'))),
        );
        device.addEventListener('inputreport', onReport);
    });
}
