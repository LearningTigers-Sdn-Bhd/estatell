import { Link, router } from '@inertiajs/react';
import { LayoutGrid, List, QrCode, Users } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { MoneyText } from '@/components/pms/money-text';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { floorVersion as floorVersionRoute, terminal } from '@/routes/pos';
import { store as storeOrder } from '@/routes/pos/orders';
import { qr as tableQr } from '@/routes/pos/tables';
import type { PosCurrency } from './pos-types';

export type FloorTableCheck = {
    id: number;
    orderNumber: string | null;
    openedAt: string;
    total: string;
};

export type FloorTable = {
    id: number;
    code: string;
    label: string | null;
    seats: number;
    shape: string;
    x: number;
    y: number;
    width: number;
    height: number;
    occupied: boolean;
    openedAt: string | null;
    covers: number;
    total: string;
    preBilled: boolean;
    multiBill: boolean;
    orderIds: number[];
    checks?: FloorTableCheck[];
};

export type Floor = { id: number; name: string; tables: FloorTable[] };

const COVER_PRESETS = [1, 2, 3, 4, 5, 6];

function minutesSince(iso: string | null, nowMs: number): string {
    if (iso === null) {
        return '';
    }

    const minutes = Math.max(
        0,
        Math.round((nowMs - new Date(iso).getTime()) / 60000),
    );

    return minutes < 60
        ? `${minutes} min`
        : `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
}

/**
 * The pay-later landing: live floor plan with multi-tablet polling, 30s elapsed ticker, map/grid toggle,
 * 1-tap cover presets, visible QR print mode, and detailed multi-bill picker.
 */
export function PosFloorBoard({
    floors,
    floorVersion,
    outletId,
    registerId,
    diningOptionId,
    currency,
    trading,
    onNewCounterOrder,
}: {
    floors: Floor[];
    floorVersion?: string | null;
    outletId: number;
    registerId: number;
    diningOptionId: number;
    currency: PosCurrency;
    trading: boolean;
    onNewCounterOrder?: () => void;
}) {
    const [active, setActive] = useState(floors[0]?.id ?? 0);
    const [covers, setCovers] = useState('2');
    const [choosing, setChoosing] = useState<FloorTable | null>(null);
    const [viewMode, setViewMode] = useState<'map' | 'grid'>('map');
    const [qrMode, setQrMode] = useState(false);
    const [nowMs, setNowMs] = useState(() => Date.now());
    const latestVersion = useRef(floorVersion ?? null);
    const pressTimer = useRef<number | null>(null);
    const longPressed = useRef(false);
    const floor = floors.find((row) => row.id === active) ?? floors[0];

    useEffect(() => {
        latestVersion.current = floorVersion ?? null;
    }, [floorVersion]);

    useEffect(() => {
        const clockTimer = window.setInterval(
            () => setNowMs(Date.now()),
            30000,
        );
        const pollTimer = window.setInterval(async () => {
            try {
                const response = await fetch(
                    floorVersionRoute.url({ outlet: outletId }),
                    { headers: { Accept: 'application/json' } },
                );

                if (!response.ok) {
                    return;
                }

                const { version } = (await response.json()) as {
                    version: string;
                };

                if (
                    latestVersion.current !== null &&
                    version !== latestVersion.current
                ) {
                    latestVersion.current = version;
                    router.reload({
                        only: ['floors', 'floorVersion', 'openOrders'],
                    });
                }
            } catch {
                // Ignore transient network drops while polling floor state.
            }
        }, 4000);

        return () => {
            window.clearInterval(clockTimer);
            window.clearInterval(pollTimer);
        };
    }, [outletId]);

    if (floor === undefined) {
        return (
            <EmptyState
                icon={LayoutGrid}
                title="No floor plan yet"
                description="Add floors and tables under POS, Floor plan to seat dine-in guests."
                action={
                    onNewCounterOrder ? (
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            disabled={!trading}
                            onClick={onNewCounterOrder}
                        >
                            New counter / takeaway check
                        </Button>
                    ) : undefined
                }
            />
        );
    }

    const issueTableQr = (table: FloorTable) => {
        setQrMode(false);
        router.post(
            tableQr.url({ table: table.id }),
            {},
            { preserveScroll: true },
        );
    };

    /** Long press on a table still works as a shortcut alongside the visible QR mode toggle. */
    const startPress = (table: FloorTable) => {
        longPressed.current = false;
        pressTimer.current = window.setTimeout(() => {
            longPressed.current = true;
            issueTableQr(table);
        }, 600);
    };

    const endPress = () => {
        if (pressTimer.current !== null) {
            window.clearTimeout(pressTimer.current);
            pressTimer.current = null;
        }
    };

    const open = (table: FloorTable) => {
        if (longPressed.current) {
            longPressed.current = false;

            return;
        }

        if (qrMode) {
            issueTableQr(table);

            return;
        }

        if (!table.occupied) {
            router.post(
                storeOrder.url(),
                {
                    register_id: registerId,
                    pos_dining_option_id: diningOptionId,
                    guest_count: Number(covers) || 1,
                    pos_table_id: table.id,
                },
                { preserveScroll: true },
            );

            return;
        }

        if (table.multiBill) {
            setChoosing(table);

            return;
        }

        router.get(
            terminal.url(
                { outlet: outletId },
                { query: { register: registerId, order: table.orderIds[0] } },
            ),
        );
    };

    const occupiedCount = floor.tables.filter((table) => table.occupied).length;
    const preBilledCount = floor.tables.filter(
        (table) => table.preBilled,
    ).length;

    return (
        <div className="flex min-h-0 flex-1 flex-col gap-3">
            <div className="flex flex-wrap items-center gap-2">
                {floors.map((row) => (
                    <Button
                        key={row.id}
                        type="button"
                        aria-pressed={row.id === floor.id}
                        className="min-h-11"
                        variant={row.id === floor.id ? 'default' : 'outline'}
                        onClick={() => setActive(row.id)}
                    >
                        {row.name}
                    </Button>
                ))}
                {onNewCounterOrder && (
                    <Button
                        type="button"
                        variant="outline"
                        className="min-h-11"
                        disabled={!trading}
                        onClick={onNewCounterOrder}
                    >
                        Counter check
                    </Button>
                )}
                <div className="ms-auto flex flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        variant={qrMode ? 'default' : 'outline'}
                        aria-pressed={qrMode}
                        className="min-h-11"
                        disabled={!trading}
                        onClick={() => setQrMode((value) => !value)}
                    >
                        <QrCode data-icon="inline-start" aria-hidden="true" />
                        Table QR
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        className="size-11"
                        aria-label={
                            viewMode === 'map'
                                ? 'Switch to compact grid'
                                : 'Switch to floor map'
                        }
                        onClick={() =>
                            setViewMode((mode) =>
                                mode === 'map' ? 'grid' : 'map',
                            )
                        }
                    >
                        {viewMode === 'map' ? (
                            <List aria-hidden="true" />
                        ) : (
                            <LayoutGrid aria-hidden="true" />
                        )}
                    </Button>
                    <div
                        className="flex flex-wrap items-center gap-1"
                        role="group"
                        aria-label="Covers for a new check"
                    >
                        <span className="pr-1 text-sm text-muted-foreground">
                            Covers
                        </span>
                        {COVER_PRESETS.map((preset) => (
                            <Button
                                key={preset}
                                type="button"
                                aria-pressed={Number(covers) === preset}
                                variant={
                                    Number(covers) === preset
                                        ? 'secondary'
                                        : 'outline'
                                }
                                className="min-h-11 min-w-11 px-2 tabular-nums"
                                onClick={() => setCovers(String(preset))}
                            >
                                {preset}
                            </Button>
                        ))}
                        <Input
                            type="number"
                            min={1}
                            max={99}
                            aria-label="Custom covers"
                            className="h-11 w-16 tabular-nums"
                            value={covers}
                            onChange={(event) => setCovers(event.target.value)}
                        />
                    </div>
                </div>
            </div>

            <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                <span className="tabular-nums">
                    {occupiedCount} of {floor.tables.length} tables occupied
                    {preBilledCount > 0
                        ? ` · ${preBilledCount} pre-billed`
                        : ''}
                </span>
                <div className="flex flex-wrap items-center gap-3">
                    <span className="flex items-center gap-1.5">
                        <span
                            aria-hidden="true"
                            className="size-2.5 rounded-xs border-2 border-border bg-background"
                        />
                        Available
                    </span>
                    <span className="flex items-center gap-1.5">
                        <span
                            aria-hidden="true"
                            className="size-2.5 rounded-xs border-2 border-primary bg-primary/15"
                        />
                        Occupied
                    </span>
                    <span className="flex items-center gap-1.5">
                        <span
                            aria-hidden="true"
                            className="size-2.5 rounded-xs border-2 border-warning bg-warning/15"
                        />
                        Pre-billed
                    </span>
                </div>
            </div>

            {qrMode && (
                <Alert>
                    <QrCode className="size-4" aria-hidden="true" />
                    <AlertDescription className="flex flex-wrap items-center justify-between gap-2">
                        <span>
                            Tap any table below to issue and print its guest
                            ordering QR slip.
                        </span>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => setQrMode(false)}
                        >
                            Cancel
                        </Button>
                    </AlertDescription>
                </Alert>
            )}

            {viewMode === 'map' ? (
                <div className="relative min-h-96 flex-1 overflow-auto rounded-xl border bg-muted/30">
                    {floor.tables.map((table) => (
                        <button
                            key={table.id}
                            type="button"
                            disabled={!trading}
                            onClick={() => open(table)}
                            onPointerDown={() => startPress(table)}
                            onPointerUp={endPress}
                            onPointerLeave={endPress}
                            onPointerCancel={endPress}
                            style={{
                                left: table.x,
                                top: table.y,
                                width: table.width,
                                height: table.height,
                            }}
                            className={cn(
                                'absolute flex flex-col items-center justify-center gap-0.5 border-2 p-1.5 text-sm transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none active:translate-y-px disabled:opacity-50',
                                table.shape === 'round'
                                    ? 'rounded-full'
                                    : 'rounded-xl',
                                qrMode
                                    ? 'border-dashed border-primary bg-card hover:bg-muted/70'
                                    : table.occupied
                                      ? table.preBilled
                                          ? 'border-warning bg-warning/10 hover:bg-warning/20'
                                          : 'border-primary bg-primary/10 hover:bg-primary/15'
                                      : 'border-border bg-card hover:border-foreground/30 hover:bg-muted/70',
                            )}
                        >
                            <span className="font-heading font-semibold text-foreground">
                                {table.code}
                            </span>
                            {table.label && (
                                <span className="max-w-full truncate text-xs text-muted-foreground">
                                    {table.label}
                                </span>
                            )}
                            {qrMode ? (
                                <span className="text-xs font-medium text-primary">
                                    Print QR
                                </span>
                            ) : table.occupied ? (
                                <>
                                    <span className="font-medium text-foreground tabular-nums">
                                        <MoneyText
                                            amount={table.total}
                                            currency={currency}
                                        />
                                    </span>
                                    <span className="flex items-center gap-1 text-xs text-muted-foreground tabular-nums">
                                        <Users
                                            className="size-3"
                                            aria-hidden="true"
                                        />
                                        {table.covers} ·{' '}
                                        {minutesSince(table.openedAt, nowMs)}
                                    </span>
                                    {table.multiBill && (
                                        <Badge variant="secondary">
                                            {table.orderIds.length} bills
                                        </Badge>
                                    )}
                                    {table.preBilled && (
                                        <Badge
                                            variant="outline"
                                            className="border-warning bg-warning/10 text-warning-text"
                                        >
                                            Pre-bill
                                        </Badge>
                                    )}
                                </>
                            ) : (
                                <span className="text-xs text-muted-foreground">
                                    {table.seats} seats
                                </span>
                            )}
                        </button>
                    ))}
                </div>
            ) : (
                <div className="grid min-h-0 flex-1 grid-cols-2 content-start gap-2.5 overflow-y-auto sm:grid-cols-3 md:grid-cols-4">
                    {floor.tables.map((table) => (
                        <button
                            key={table.id}
                            type="button"
                            disabled={!trading}
                            onClick={() => open(table)}
                            onPointerDown={() => startPress(table)}
                            onPointerUp={endPress}
                            onPointerLeave={endPress}
                            onPointerCancel={endPress}
                            className={cn(
                                'flex min-h-24 flex-col items-start justify-between gap-1.5 rounded-xl border-2 p-3 text-left text-sm transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none active:translate-y-px disabled:opacity-50',
                                qrMode
                                    ? 'border-dashed border-primary bg-card hover:bg-muted/70'
                                    : table.occupied
                                      ? table.preBilled
                                          ? 'border-warning bg-warning/10 hover:bg-warning/20'
                                          : 'border-primary bg-primary/10 hover:bg-primary/15'
                                      : 'border-border bg-card hover:border-foreground/30 hover:bg-muted/70',
                            )}
                        >
                            <div className="flex w-full items-center justify-between gap-2">
                                <span className="font-heading font-semibold text-foreground">
                                    {table.code}
                                </span>
                                {qrMode ? (
                                    <Badge variant="secondary">QR</Badge>
                                ) : table.preBilled ? (
                                    <Badge
                                        variant="outline"
                                        className="border-warning bg-warning/10 text-warning-text"
                                    >
                                        Pre-bill
                                    </Badge>
                                ) : table.occupied ? (
                                    <Badge variant="secondary">Seated</Badge>
                                ) : null}
                            </div>
                            {table.label && (
                                <span className="max-w-full truncate text-xs text-muted-foreground">
                                    {table.label}
                                </span>
                            )}
                            {table.occupied ? (
                                <div className="flex w-full items-center justify-between text-xs tabular-nums">
                                    <span className="font-semibold text-foreground">
                                        <MoneyText
                                            amount={table.total}
                                            currency={currency}
                                        />
                                    </span>
                                    <span className="text-muted-foreground">
                                        {table.covers} ·{' '}
                                        {minutesSince(table.openedAt, nowMs)}
                                    </span>
                                </div>
                            ) : (
                                <span className="text-xs text-muted-foreground">
                                    {table.seats} seats · Available
                                </span>
                            )}
                        </button>
                    ))}
                </div>
            )}

            {choosing !== null && (
                <div className="flex flex-col gap-2 rounded-xl border bg-card p-3.5">
                    <div className="flex items-center justify-between gap-2">
                        <span className="text-sm font-medium">
                            Table {choosing.code} has {choosing.orderIds.length}{' '}
                            open checks:
                        </span>
                        <div className="flex items-center gap-1.5">
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                className="min-h-11"
                                onClick={() => issueTableQr(choosing)}
                            >
                                <QrCode
                                    data-icon="inline-start"
                                    aria-hidden="true"
                                />
                                Table QR
                            </Button>
                            <Button
                                type="button"
                                className="min-h-11"
                                variant="ghost"
                                onClick={() => setChoosing(null)}
                            >
                                Cancel
                            </Button>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {(
                            choosing.checks ??
                            choosing.orderIds.map((id) => ({
                                id,
                                orderNumber: null,
                                openedAt:
                                    choosing.openedAt ??
                                    new Date().toISOString(),
                                total: '0.00',
                            }))
                        ).map((check) => (
                            <Link
                                key={check.id}
                                href={terminal.url(
                                    { outlet: outletId },
                                    {
                                        query: {
                                            register: registerId,
                                            order: check.id,
                                        },
                                    },
                                )}
                                className={buttonVariants({
                                    variant: 'secondary',
                                    className:
                                        'h-auto min-h-11 flex-col items-start gap-0.5 py-2',
                                })}
                            >
                                <span className="font-medium">
                                    {check.orderNumber ?? `Check #${check.id}`}
                                </span>
                                <span className="text-xs text-muted-foreground tabular-nums">
                                    <MoneyText
                                        amount={check.total}
                                        currency={currency}
                                    />{' '}
                                    ·{' '}
                                    {new Date(
                                        check.openedAt,
                                    ).toLocaleTimeString([], {
                                        hour: '2-digit',
                                        minute: '2-digit',
                                    })}
                                </span>
                            </Link>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}
