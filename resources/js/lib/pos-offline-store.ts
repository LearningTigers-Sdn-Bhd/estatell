/**
 * The register's own storage for offline selling: the menu snapshot it last took, and the sales not yet synced. Plain
 * IndexedDB, no library. A sale stays here until the server says it was synced (or replayed).
 * See docs/34-native-pos/06-kitchen-kds-and-qr.md.
 */

const DB_NAME = 'estatell-pos';
const DB_VERSION = 1;
const SNAPSHOTS = 'snapshots';
const QUEUE = 'queue';

export type OfflineTender = {
    tender_type: 'cash' | 'duitnow_qr';
    amount: string | null;
    tendered_amount: string | null;
};

export type QueuedSale = {
    clientUuid: string;
    reference: string;
    snapshotVersion: string;
    shiftId: number | null;
    lines: { variant_id: number; quantity: number }[];
    lineDetails?: {
        name: string;
        variantName: string;
        quantity: number;
        unitPrice: string;
    }[];
    estimatedSubtotal?: string;
    /** What the cashier was told to collect, and the cash taken, for the slip. Display only. */
    slipTotals?: {
        serviceCharge: string;
        total: string;
        handedOver: string | null;
        change: string | null;
    };
    tenders: OfflineTender[];
    summary: string;
    createdAt: string;
};

export type StoredSnapshot = {
    outletId: number;
    version: string;
    payload: unknown;
};

function open(): Promise<IDBDatabase> {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);

        request.onupgradeneeded = () => {
            const db = request.result;

            if (!db.objectStoreNames.contains(SNAPSHOTS)) {
                db.createObjectStore(SNAPSHOTS, { keyPath: 'outletId' });
            }

            if (!db.objectStoreNames.contains(QUEUE)) {
                db.createObjectStore(QUEUE, { keyPath: 'clientUuid' });
            }
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

function run<T>(
    store: string,
    mode: IDBTransactionMode,
    action: (target: IDBObjectStore) => IDBRequest<T>,
): Promise<T> {
    return open().then(
        (db) =>
            new Promise<T>((resolve, reject) => {
                const transaction = db.transaction(store, mode);
                const request = action(transaction.objectStore(store));

                transaction.oncomplete = () => {
                    db.close();
                    resolve(request.result);
                };
                transaction.onerror = () => {
                    db.close();
                    reject(transaction.error);
                };
            }),
    );
}

export function saveSnapshot(snapshot: StoredSnapshot): Promise<IDBValidKey> {
    return run(SNAPSHOTS, 'readwrite', (store) => store.put(snapshot));
}

export function loadSnapshot(
    outletId: number,
): Promise<StoredSnapshot | undefined> {
    return run(SNAPSHOTS, 'readonly', (store) => store.get(outletId));
}

export function enqueueSale(sale: QueuedSale): Promise<IDBValidKey> {
    return run(QUEUE, 'readwrite', (store) => store.put(sale));
}

export function listQueue(): Promise<QueuedSale[]> {
    return run(QUEUE, 'readonly', (store) => store.getAll()).then((rows) =>
        [...rows].sort((a, b) => a.createdAt.localeCompare(b.createdAt)),
    );
}

export function removeSales(clientUuids: string[]): Promise<void> {
    return open().then(
        (db) =>
            new Promise<void>((resolve, reject) => {
                const transaction = db.transaction(QUEUE, 'readwrite');
                const store = transaction.objectStore(QUEUE);

                clientUuids.forEach((uuid) => store.delete(uuid));
                transaction.oncomplete = () => {
                    db.close();
                    resolve();
                };
                transaction.onerror = () => {
                    db.close();
                    reject(transaction.error);
                };
            }),
    );
}
