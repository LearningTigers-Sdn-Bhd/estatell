import { Head } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { MoneyText } from '@/components/pms/money-text';
import {
    noApproval,
    PosApprovalFields,
} from '@/components/pms/pos/pos-approval-fields';
import type { ApprovalData } from '@/components/pms/pos/pos-approval-fields';
import type { PosStaff } from '@/components/pms/pos/pos-types';
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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
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
    currency: App.Data.Property.CurrencyData | null;
    staff: PosStaff[];
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
    currency,
    staff,
}: Props) {
    const [snapshot, setSnapshot] = useState<StoredSnapshot | null>(null);
    const [queue, setQueue] = useState<QueuedSale[]>([]);
    const [online, setOnline] = useState(() => navigator.onLine);
    const [pending, setPending] = useState<QrLine[] | null>(null);
    const [tender, setTender] = useState<'cash' | 'duitnow_qr'>('cash');
    const [tendered, setTendered] = useState('');
    const [message, setMessage] = useState<string | null>(null);
    const [syncing, setSyncing] = useState(false);
    const [needsApproval, setNeedsApproval] = useState(false);
    const [approval, setApproval] = useState<ApprovalData>(noApproval);
    const [slipSale, setSlipSale] = useState<QueuedSale | null>(null);

    const refreshQueue = () => listQueue().then(setQueue);

    useEffect(() => {
        void navigator.serviceWorker?.register('/sw.js').catch(() => undefined);
        void loadSnapshot(outlet.id).then((stored) =>
            setSnapshot(stored ?? null),
        );
        void refreshQueue();

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

                return saveSnapshot(stored).then(() => setSnapshot(stored));
            })
            .catch(() => undefined);
    }, [online, outlet.id]);

    const payload = snapshot?.payload as Snapshot | undefined;
    const menu = payload ? offlineMenu(payload.categories) : [];

    const variantLookup = useMemo(() => {
        const map = new Map<
            number,
            { itemName: string; variantName: string; price: string }
        >();

        for (const category of payload?.categories ?? []) {
            for (const item of category.items) {
                for (const variant of item.variants) {
                    map.set(variant.id, {
                        itemName: item.name,
                        variantName: variant.name,
                        price: variant.price,
                    });
                }
            }
        }

        return map;
    }, [payload]);

    const pendingDetails = useMemo(
        () =>
            (pending ?? []).map((line) => {
                const info = variantLookup.get(line.variant_id);

                return {
                    name: info?.itemName ?? `Item #${line.variant_id}`,
                    variantName: info?.variantName ?? 'Regular',
                    quantity: line.quantity,
                    unitPrice: info?.price ?? '0.00',
                };
            }),
        [pending, variantLookup],
    );

    const estimatedSubtotal = useMemo(
        () =>
            pendingDetails
                .reduce(
                    (sum, row) => sum + Number(row.unitPrice) * row.quantity,
                    0,
                )
                .toFixed(2),
        [pendingDetails],
    );

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
            lineDetails: pendingDetails,
            estimatedSubtotal,
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

        void enqueueSale(sale).then(() => {
            setPending(null);
            setTendered('');
            setSlipSale(sale);
            setMessage(
                `Saved on this device as ${sale.reference}. The official receipt number is given on sync.`,
            );

            return refreshQueue();
        });
    };

    const sync = async (withApproval = false) => {
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
                    ...(withApproval
                        ? {
                              approve: true,
                              approver_id: approval.approver_id || null,
                              approver_pin: approval.approver_pin || null,
                          }
                        : {}),
                }),
            });

            if (!response.ok) {
                const body = (await response.json().catch(() => ({}))) as {
                    error?: string;
                };
                setMessage(
                    body.error ??
                        'The sync was refused. Check manager approval details and try again.',
                );

                return;
            }

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

            const approvalNeeded = refused.some(
                ([, outcome]) =>
                    outcome === 'price_drift_approval_required' ||
                    outcome === 'snapshot_expired',
            );
            setNeedsApproval(approvalNeeded);
            if (!approvalNeeded) {
                setApproval(noApproval);
            }

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

    const currencyCode = currency?.code ?? 'MYR';

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
                        currency={currency}
                        allowRemark={false}
                        sending={false}
                        onSend={(lines, clearCart) => {
                            setPending(lines);
                            clearCart();
                        }}
                    />
                ) : (
                    <Card>
                        <CardHeader>
                            <CardTitle>Take payment</CardTitle>
                            <CardDescription>
                                Estimated item subtotal below. Taxes and service
                                charge are finalised when the sale is synced.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <div className="flex flex-col gap-1 rounded-lg border bg-muted/40 p-3 text-sm">
                                {pendingDetails.map((row, index) => (
                                    <div
                                        key={`${row.name}-${row.variantName}-${index}`}
                                        className="flex items-center justify-between gap-2"
                                    >
                                        <span>
                                            {row.quantity} × {row.name}
                                            {row.variantName !== 'Regular'
                                                ? ` (${row.variantName})`
                                                : ''}
                                        </span>
                                        <span className="tabular-nums">
                                            <MoneyText
                                                amount={(
                                                    Number(row.unitPrice) *
                                                    row.quantity
                                                ).toFixed(2)}
                                                currency={currency}
                                            />
                                        </span>
                                    </div>
                                ))}
                                <div className="mt-2 flex items-center justify-between border-t pt-2 font-medium">
                                    <span>Estimated subtotal</span>
                                    <span className="tabular-nums">
                                        <MoneyText
                                            amount={estimatedSubtotal}
                                            currency={currency}
                                        />
                                    </span>
                                </div>
                            </div>

                            <div className="flex gap-2">
                                <Button
                                    variant={
                                        tender === 'cash'
                                            ? 'default'
                                            : 'outline'
                                    }
                                    size="lg"
                                    className="min-h-11"
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
                                    className="min-h-11"
                                    onClick={() => setTender('duitnow_qr')}
                                >
                                    DuitNow QR
                                </Button>
                            </div>
                            {tender === 'cash' && (
                                <Field>
                                    <FieldLabel htmlFor="offline-tendered">
                                        Cash handed over ({currencyCode})
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
                                    className="min-h-11"
                                    onClick={() => setPending(null)}
                                >
                                    Back
                                </Button>
                                <Button
                                    size="lg"
                                    className="min-h-11"
                                    onClick={takeSale}
                                >
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
                        <ul className="flex flex-col gap-2 text-sm">
                            {queue.map((sale) => (
                                <li
                                    key={sale.clientUuid}
                                    className="flex flex-wrap items-center justify-between gap-2 rounded-lg border px-3 py-2"
                                >
                                    <div className="flex flex-col">
                                        <span className="font-mono font-medium">
                                            {sale.reference}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {sale.summary}
                                            {sale.estimatedSubtotal !==
                                                undefined && (
                                                <>
                                                    {' · est. '}
                                                    <MoneyText
                                                        amount={
                                                            sale.estimatedSubtotal
                                                        }
                                                        currency={currency}
                                                    />
                                                </>
                                            )}
                                        </span>
                                    </div>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="min-h-11"
                                        onClick={() => setSlipSale(sale)}
                                    >
                                        <Printer data-icon="inline-start" />
                                        Print slip
                                    </Button>
                                </li>
                            ))}
                        </ul>

                        {needsApproval && queue.length > 0 && (
                            <div className="border-warning-border bg-warning-bg/20 flex flex-col gap-3 rounded-lg border p-3">
                                <p className="text-sm font-medium text-warning-text">
                                    Manager approval required to override price
                                    drift or an expired snapshot.
                                </p>
                                <PosApprovalFields
                                    staff={staff}
                                    data={approval}
                                    onChange={setApproval}
                                    required
                                />
                                <Button
                                    size="lg"
                                    className="min-h-11"
                                    disabled={
                                        !online || queue.length === 0 || syncing
                                    }
                                    focusableWhenDisabled
                                    onClick={() => void sync(true)}
                                >
                                    {syncing && (
                                        <Spinner data-icon="inline-start" />
                                    )}
                                    Approve and sync
                                </Button>
                            </div>
                        )}

                        <Button
                            size="lg"
                            className="min-h-11"
                            disabled={!online || queue.length === 0 || syncing}
                            focusableWhenDisabled
                            onClick={() => void sync(false)}
                        >
                            {syncing && <Spinner data-icon="inline-start" />}
                            Sync now
                        </Button>
                    </CardContent>
                </Card>
            </div>

            <Dialog
                open={slipSale !== null}
                onOpenChange={(open) => !open && setSlipSale(null)}
            >
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle>Provisional offline slip</DialogTitle>
                        <DialogDescription>
                            Official receipt number is issued when this sale
                            syncs.
                        </DialogDescription>
                    </DialogHeader>
                    {slipSale !== null && (
                        <div className="pos-print-area flex flex-col gap-2 rounded-lg border bg-background p-4 font-mono text-xs">
                            <div className="text-center font-semibold">
                                {outlet.name}
                            </div>
                            <div className="text-center text-muted-foreground">
                                PROVISIONAL OFFLINE SLIP
                            </div>
                            <div className="flex justify-between border-t pt-2">
                                <span>Ref</span>
                                <span>{slipSale.reference}</span>
                            </div>
                            <div className="flex justify-between">
                                <span>Time</span>
                                <span>
                                    {new Date(
                                        slipSale.createdAt,
                                    ).toLocaleString()}
                                </span>
                            </div>
                            <div className="flex flex-col gap-1 border-t pt-2">
                                {(slipSale.lineDetails ?? []).map(
                                    (row, index) => (
                                        <div
                                            key={`${row.name}-${index}`}
                                            className="flex justify-between gap-2"
                                        >
                                            <span>
                                                {row.quantity} × {row.name}
                                                {row.variantName !== 'Regular'
                                                    ? ` (${row.variantName})`
                                                    : ''}
                                            </span>
                                            <span className="tabular-nums">
                                                <MoneyText
                                                    amount={(
                                                        Number(row.unitPrice) *
                                                        row.quantity
                                                    ).toFixed(2)}
                                                    currency={currency}
                                                />
                                            </span>
                                        </div>
                                    ),
                                )}
                            </div>
                            {slipSale.estimatedSubtotal !== undefined && (
                                <div className="flex justify-between border-t pt-2 font-semibold">
                                    <span>Est. subtotal</span>
                                    <span className="tabular-nums">
                                        <MoneyText
                                            amount={slipSale.estimatedSubtotal}
                                            currency={currency}
                                        />
                                    </span>
                                </div>
                            )}
                            <p className="border-t pt-2 text-center text-muted-foreground">
                                Not an official tax invoice. Official receipt
                                issued on sync.
                            </p>
                        </div>
                    )}
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setSlipSale(null)}
                        >
                            Close
                        </Button>
                        <Button onClick={() => window.print()}>
                            <Printer data-icon="inline-start" />
                            Print slip
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
