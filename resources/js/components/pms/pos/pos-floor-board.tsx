import { Link, router } from '@inertiajs/react';
import { LayoutGrid, List, Users } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { MoneyText } from '@/components/pms/money-text';
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
 * The pay-later landing: live floor plan with multi-tablet polling, 30s elapsed ticker, map/list toggle,
 * 1-tap cover presets, and detailed multi-bill picker.
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
            <div className="flex flex-col items-start gap-3">
                <p className="text-sm text-muted-foreground">
                    No floor plan yet. Add floors and tables under Settings,
                    POS, Floor plan.
                </p>
                {onNewCounterOrder && (
                    <Button
                        type="button"
                        variant="outline"
                        className="min-h-11"
                        disabled={!trading}
                        onClick={onNewCounterOrder}
                    >
                        New counter / takeaway check
                    </Button>
                )}
            </div>
        );
    }

    /** A long press on a table prints its ordering link, and the click that would open the table is skipped. */
    const startPress = (table: FloorTable) => {
        longPressed.current = false;
        pressTimer.current = window.setTimeout(() => {
            longPressed.current = true;
            router.post(
                tableQr.url({ table: table.id }),
                {},
                { preserveScroll: true },
            );
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

    return (
        <div className="flex min-h-0 flex-1 flex-col gap-3">
            <div className="flex flex-wrap items-center gap-2">
                {floors.map((row) => (
                    <Button
                        key={row.id}
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
                        New counter / takeaway check
                    </Button>
                )}
                <div className="ms-auto flex flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        className="min-h-11 min-w-11"
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
                        {viewMode === 'map' ? <List /> : <LayoutGrid />}
                    </Button>
                    <div
                        className="flex items-center gap-1"
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

            {viewMode === 'map' ? (
                <div className="relative min-h-96 flex-1 overflow-auto rounded-lg border bg-muted/30">
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
                                'absolute flex flex-col items-center justify-center gap-0.5 border-2 p-1 text-sm transition-colors disabled:opacity-50',
                                table.shape === 'round'
                                    ? 'rounded-full'
                                    : 'rounded-lg',
                                table.occupied
                                    ? table.preBilled
                                        ? 'border-warning bg-warning/10'
                                        : 'border-primary bg-primary/10'
                                    : 'border-border bg-background hover:bg-accent',
                            )}
                        >
                            <span className="font-semibold">{table.code}</span>
                            {table.label && (
                                <span className="max-w-full truncate text-[11px] text-muted-foreground">
                                    {table.label}
                                </span>
                            )}
                            {table.occupied ? (
                                <>
                                    <span className="tabular-nums">
                                        <MoneyText
                                            amount={table.total}
                                            currency={currency}
                                        />
                                    </span>
                                    <span className="flex items-center gap-1 text-xs text-muted-foreground">
                                        <Users className="size-3" aria-hidden />
                                        {table.covers} ·{' '}
                                        {minutesSince(table.openedAt, nowMs)}
                                    </span>
                                    {table.multiBill && (
                                        <Badge variant="secondary">
                                            {table.orderIds.length} bills
                                        </Badge>
                                    )}
                                    {table.preBilled && (
                                        <Badge variant="outline">
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
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4">
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
                                'flex min-h-24 flex-col items-start justify-between gap-1 rounded-lg border-2 p-3 text-left text-sm transition-colors disabled:opacity-50',
                                table.occupied
                                    ? table.preBilled
                                        ? 'border-warning bg-warning/10'
                                        : 'border-primary bg-primary/10'
                                    : 'border-border bg-background hover:bg-accent',
                            )}
                        >
                            <div className="flex w-full items-center justify-between gap-2">
                                <span className="font-semibold">
                                    {table.code}
                                </span>
                                {table.preBilled && (
                                    <Badge variant="outline">Pre-bill</Badge>
                                )}
                            </div>
                            {table.label && (
                                <span className="max-w-full truncate text-xs text-muted-foreground">
                                    {table.label}
                                </span>
                            )}
                            {table.occupied ? (
                                <div className="flex w-full items-center justify-between text-xs">
                                    <MoneyText
                                        amount={table.total}
                                        currency={currency}
                                    />
                                    <span className="text-muted-foreground">
                                        {table.covers} ·{' '}
                                        {minutesSince(table.openedAt, nowMs)}
                                    </span>
                                </div>
                            ) : (
                                <span className="text-xs text-muted-foreground">
                                    {table.seats} seats
                                </span>
                            )}
                        </button>
                    ))}
                </div>
            )}

            {choosing !== null && (
                <div className="flex flex-col gap-2 rounded-lg border p-3">
                    <div className="flex items-center justify-between gap-2">
                        <span className="text-sm font-medium">
                            Table {choosing.code} has {choosing.orderIds.length}{' '}
                            open checks:
                        </span>
                        <Button
                            className="min-h-11"
                            variant="ghost"
                            onClick={() => setChoosing(null)}
                        >
                            Cancel
                        </Button>
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
