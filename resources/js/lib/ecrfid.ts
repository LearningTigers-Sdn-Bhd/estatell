/**
 * Reads the UID of a card on the desk's ECRFID 13.56 MHz reader (USB HID FFFE:0091) from the browser, with WebHID.
 * Framing and inventory follow elock-dock/internal/ecrfid/reader.go. It only asks "which cards are in the field";
 * it never authenticates or writes a card. Needs Chrome or Edge over HTTPS (localhost counts).
 */

type HidDevice = {
    opened: boolean;
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
export function parseInventoryReport(f: number[]): number[] | null {
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
    timeoutMs = 30_000,
): Promise<string> {
    const hid = (navigator as unknown as { hid?: Hid }).hid;

    if (!hid) {
        throw new Error('Reading cards needs Chrome or Edge.');
    }

    const device = await pickReader(hid);

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

    onReady?.();

    const request = frame(INVENTORY.type, INVENTORY.code, [0]);
    const report = new Uint8Array(request);

    return new Promise<string>((resolve, reject) => {
        const finish = (result: () => void) => {
            clearInterval(poll);
            clearTimeout(timeout);
            device.removeEventListener('inputreport', onReport);
            void device.close().finally(result);
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
                .sendReport(report.length, report)
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
            () =>
                finish(() =>
                    reject(new Error('No card was held on the reader.')),
                ),
            timeoutMs,
        );

        signal?.addEventListener('abort', () =>
            finish(() => reject(new DOMException('Cancelled', 'AbortError'))),
        );
        device.addEventListener('inputreport', onReport);
    });
}
