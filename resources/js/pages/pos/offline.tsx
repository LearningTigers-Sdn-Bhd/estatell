import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { QrMenu } from '@/components/pms/qr/qr-menu';
import type { QrCategory, QrLine } from '@/components/pms/qr/qr-menu';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    enqueueSale,
    listQueue,
    loadSnapshot,
    removeSales,
    saveSnapshot,
} from '@/lib/pos-offline-store';
import type { QueuedSale, StoredSnapshot } from '@/lib/pos-offline-store';
import {
    snapshot as offlineSnapshot,
    sync as offlineSync,
} from '@/routes/pos/offline';

type Props = {
    outlet: { id: number; name: string };
    shiftId: number | null;
    registerId: number | null;
    registerName: string | null;
};

type Snapshot = { version: string; categories: SnapshotCategory[] };

type SnapshotVariant = {
    id: number;
    name: string;
    price: string;
    soldOut: boolean;
    pricingType: string;
};

type SnapshotItem = {
    id: number;
    name: string;
    isCombo: boolean;
    modifierGroups: { minSelect: number }[];
    variants: SnapshotVariant[];
};

type SnapshotCategory = { id: number; name: string; items: SnapshotItem[] };

/**
 * Only what can be sold offline: no combo, no item whose modifier must be picked, and no changing price. The same rule the
 * server applies to a QR menu, plus the variable-price rule.
 */
function offlineMenu(categories: SnapshotCategory[]): QrCategory[] {
    return categories
        .map((category) => ({
            id: category.id,
            name: category.name,
            items: category.items
                .filter(
                    (item) =>
                        !item.isCombo &&
                        item.modifierGroups.every(
                            (group) => group.minSelect === 0,
                        ) &&
                        item.variants.every(
                            (variant) => variant.pricingType !== 'variable',
                        ),
                )
                .map((item) => ({
                    id: item.id,
                    name: item.name,
                    variants: item.variants,
                })),
        }))
        .filter((category) => category.items.length > 0);
}

function csrfHeader(): Record<string, string> {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? { 'X-XSRF-TOKEN': decodeURIComponent(match[1]) } : {};
}

export default function PosOffline({
    outlet,
    shiftId,
    registerId,
    registerName,
}: Props) {
    const [snapshot, setSnapshot] = useState<StoredSnapshot | null>(null);
    const [queue, setQueue] = useState<QueuedSale[]>([]);
    const [online, setOnline] = useState(() => navigator.onLine);
    const [pending, setPending] = useState<QrLine[] | null>(null);
    const [tender, setTender] = useState<'cash' | 'duitnow_qr'>('cash');
    const [tendered, setTendered] = useState('');
    const [message, setMessage] = useState<string | null>(null);
    const [syncing, setSyncing] = useState(false);

    const refreshQueue = () => listQueue().then(setQueue);

    useEffect(() => {
        navigator.serviceWorker?.register('/sw.js').catch(() => undefined);
        loadSnapshot(outlet.id).then((stored) => setSnapshot(stored ?? null));
        refreshQueue();

        const up = () => setOnline(true);
        const down = () => setOnline(false);
        window.addEventListener('online', up);
        window.addEventListener('offline', down);

        return () => {
            window.removeEventListener('online', up);
            window.removeEventListener('offline', down);
        };
    }, [outlet.id]);

    useEffect(() => {
        if (!online) {
            return;
        }

        fetch(
            offlineSnapshot.url(undefined, { query: { outlet: outlet.id } }),
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            },
        )
            .then((response) => (response.ok ? response.json() : null))
            .then((payload: Snapshot | null) => {
                if (payload === null) {
                    return;
                }

                const stored = {
                    outletId: outlet.id,
                    version: payload.version,
                    payload,
                };
                saveSnapshot(stored).then(() => setSnapshot(stored));
            })
            .catch(() => undefined);
    }, [online, outlet.id]);

    const payload = snapshot?.payload as Snapshot | undefined;
    const menu = payload ? offlineMenu(payload.categories) : [];

    const takeSale = () => {
        if (pending === null || payload === undefined || shiftId === null) {
            setMessage('Open a shift on this register before selling offline.');

            return;
        }

        const clientUuid = crypto.randomUUID();
        const sale: QueuedSale = {
            clientUuid,
            reference: `OFF-${registerId ?? 0}-${clientUuid.slice(0, 8)}`,
            snapshotVersion: payload.version,
            shiftId,
            lines: pending.map((line) => ({
                variant_id: line.variant_id,
                quantity: line.quantity,
            })),
            tenders: [
                {
                    tender_type: tender,
                    amount: null,
                    tendered_amount:
                        tender === 'cash' && tendered.trim() !== ''
                            ? tendered.trim()
                            : null,
                },
            ],
            summary: `${pending.reduce((sum, line) => sum + line.quantity, 0)} item(s)`,
            createdAt: new Date().toISOString(),
        };

        enqueueSale(sale).then(() => {
            setPending(null);
            setTendered('');
            setMessage(
                `Saved on this device as ${sale.reference}. The official receipt number is given on sync.`,
            );
            refreshQueue();
        });
    };

    const sync = async () => {
        if (queue.length === 0) {
            return;
        }

        setSyncing(true);

        try {
            const response = await fetch(offlineSync.url(undefined), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    ...csrfHeader(),
                },
                body: JSON.stringify({
                    outlet_id: outlet.id,
                    orders: queue.map((sale) => ({
                        client_uuid: sale.clientUuid,
                        snapshot_version: sale.snapshotVersion,
                        shift_id: sale.shiftId,
                        lines: sale.lines,
                        tenders: sale.tenders,
                    })),
                }),
            });

            const { results } = (await response.json()) as {
                results: Record<string, string>;
            };
            const done = Object.entries(results)
                .filter(
                    ([, outcome]) =>
                        outcome === 'synced' || outcome === 'replayed',
                )
                .map(([uuid]) => uuid);
            const refused = Object.entries(results).filter(
                ([, outcome]) => outcome !== 'synced' && outcome !== 'replayed',
            );

            await removeSales(done);
            await refreshQueue();
            setMessage(
                refused.length === 0
                    ? `${done.length} sale(s) synced.`
                    : `${done.length} synced. ${refused.length} kept on this device: ${refused.map(([, outcome]) => outcome).join(', ')}.`,
            );
        } catch {
            setMessage(
                'The sync did not reach the server. The sales are still saved on this device.',
            );
        } finally {
            setSyncing(false);
        }
    };

    return (
        <>
            <Head title={`Offline walk-in · ${outlet.name}`} />
            <div className="flex flex-col gap-4 p-4">
                <header className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex flex-col">
                        <h1 className="text-xl font-semibold">
                            {outlet.name} · Walk-in
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {registerName ?? 'Register'} · cash and static
                            DuitNow only
                        </p>
                    </div>
                    {!online && (
                        <Badge variant="destructive">Offline mode</Badge>
                    )}
                </header>

                {message !== null && (
                    <Card>
                        <CardContent className="p-4 text-sm">
                            {message}
                        </CardContent>
                    </Card>
                )}

                {payload === undefined ? (
                    <Card>
                        <CardHeader>
                            <CardTitle>No menu on this device yet</CardTitle>
                            <CardDescription>
                                Connect once to load the menu. Then it works
                                offline.
                            </CardDescription>
                        </CardHeader>
                    </Card>
                ) : pending === null ? (
                    <QrMenu
                        categories={menu}
                        allowRemark={false}
                        sending={false}
                        onSend={(lines) => setPending(lines)}
                    />
                ) : (
                    <Card>
                        <CardHeader>
                            <CardTitle>Take payment</CardTitle>
                            <CardDescription>
                                The total is worked out with tax when the sale
                                is synced. Cash needs the amount handed over.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <div className="flex gap-2">
                                <Button
                                    variant={
                                        tender === 'cash'
                                            ? 'default'
                                            : 'outline'
                                    }
                                    size="lg"
                                    onClick={() => setTender('cash')}
                                >
                                    Cash
                                </Button>
                                <Button
                                    variant={
                                        tender === 'duitnow_qr'
                                            ? 'default'
                                            : 'outline'
                                    }
                                    size="lg"
                                    onClick={() => setTender('duitnow_qr')}
                                >
                                    DuitNow QR
                                </Button>
                            </div>
                            {tender === 'cash' && (
                                <Field>
                                    <FieldLabel htmlFor="offline-tendered">
                                        Cash handed over (RM)
                                    </FieldLabel>
                                    <Input
                                        id="offline-tendered"
                                        inputMode="decimal"
                                        value={tendered}
                                        onChange={(event) =>
                                            setTendered(event.target.value)
                                        }
                                    />
                                </Field>
                            )}
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    size="lg"
                                    onClick={() => setPending(null)}
                                >
                                    Back
                                </Button>
                                <Button size="lg" onClick={takeSale}>
                                    Save sale
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Waiting to sync</CardTitle>
                        <CardDescription>
                            {queue.length === 0
                                ? 'Nothing waiting.'
                                : `${queue.length} sale(s) on this device.`}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-3">
                        <ul className="flex flex-col gap-1 text-sm">
                            {queue.map((sale) => (
                                <li
                                    key={sale.clientUuid}
                                    className="flex justify-between gap-2"
                                >
                                    <span className="font-mono">
                                        {sale.reference}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {sale.summary}
                                    </span>
                                </li>
                            ))}
                        </ul>
                        <Button
                            size="lg"
                            disabled={!online || queue.length === 0 || syncing}
                            onClick={sync}
                        >
                            Sync now
                        </Button>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
