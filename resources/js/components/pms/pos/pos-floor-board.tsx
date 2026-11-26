import { Link, router } from '@inertiajs/react';
import {
    ArrowRight,
    Clock,
    LayoutGrid,
    Map,
    Minus,
    Plus,
    QrCode,
    Receipt,
    Users,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { MoneyText } from '@/components/pms/money-text';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverHeader,
    PopoverTitle,
    PopoverTrigger,
} from '@/components/ui/popover';
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

type TableFilter = 'all' | 'available' | 'occupied' | 'prebilled';

const COVER_PRESETS = [1, 2, 3, 4, 5, 6, 8];

function formatElapsed(iso: string | null, nowMs: number): string {
    if (iso === null) {
        return '';
    }

    const minutes = Math.max(
        0,
        Math.round((nowMs - new Date(iso).getTime()) / 60000),
    );

    if (minutes < 60) {
        return `${minutes}m`;
    }

    return `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
}

function matchesFilter(table: FloorTable, filter: TableFilter): boolean {
    if (filter === 'available') {
        return !table.occupied;
    }

    if (filter === 'occupied') {
        return table.occupied;
    }

    if (filter === 'prebilled') {
        return table.preBilled;
    }

    return true;
}

/** Architectural chair marks rendered along top and bottom of spatial map tables. */
function MapSeatTicks({
    seats,
    tone,
}: {
    seats: number;
    tone: 'available' | 'occupied' | 'prebilled' | 'qr';
}) {
    const visibleSeats = Math.max(2, Math.min(8, seats));
    const topCount = Math.ceil(visibleSeats / 2);
    const bottomCount = Math.floor(visibleSeats / 2);
    const tickClass = cn(
        'h-1 w-3.5 rounded-full',
        tone === 'occupied'
            ? 'bg-brand-forest/65'
            : tone === 'prebilled'
              ? 'bg-warning/75'
              : tone === 'qr'
                ? 'bg-primary/60'
                : 'bg-border',
    );

    return (
        <>
            <span
                aria-hidden="true"
                className="pointer-events-none absolute inset-x-3 -top-2 flex justify-evenly"
            >
                {Array.from({ length: topCount }, (_, index) => (
                    <span key={index} className={tickClass} />
                ))}
            </span>
            {bottomCount > 0 && (
                <span
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-x-3 -bottom-2 flex justify-evenly"
                >
                    {Array.from({ length: bottomCount }, (_, index) => (
                        <span key={index} className={tickClass} />
                    ))}
                </span>
            )}
        </>
    );
}

/**
 * Pay-later floor board: spacious modern hospitality table cards by default with an instant
 * architectural floor-map view, integrated status filters, cover picker popover, Table QR mode,
 * live multi-tablet polling, and multi-bill check selector dialog.
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
    const [coversOpen, setCoversOpen] = useState(false);
    const [choosing, setChoosing] = useState<FloorTable | null>(null);
    const [viewMode, setViewMode] = useState<'grid' | 'map'>('grid');
    const [filter, setFilter] = useState<TableFilter>('all');
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
                // Keep polling quietly across transient network blips.
            }
        }, 4000);

        return () => {
            window.clearInterval(clockTimer);
            window.clearInterval(pollTimer);
        };
    }, [outletId]);

    if (floor === undefined) {
        return (
            <div className="flex flex-1 items-center justify-center rounded-2xl border bg-card p-8">
                <EmptyState
                    icon={LayoutGrid}
                    title="No floor plan yet"
                    description="Add floors and tables in POS under Floor plan to seat dine-in guests."
                    action={
                        onNewCounterOrder ? (
                            <Button
                                type="button"
                                className="min-h-11"
                                disabled={!trading}
                                onClick={onNewCounterOrder}
                            >
                                <Plus
                                    data-icon="inline-start"
                                    aria-hidden="true"
                                />
                                Counter check
                            </Button>
                        ) : undefined
                    }
                />
            </div>
        );
    }

    const issueTableQr = (table: FloorTable) => {
        setQrMode(false);
        setChoosing(null);
        router.post(
            tableQr.url({ table: table.id }),
            {},
            { preserveScroll: true },
        );
    };

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

    const coverCount = Math.max(1, Math.min(99, Number(covers) || 1));

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
                    guest_count: coverCount,
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

    const totalCount = floor.tables.length;
    const occupiedCount = floor.tables.filter((table) => table.occupied).length;
    const availableCount = totalCount - occupiedCount;
    const preBilledCount = floor.tables.filter(
        (table) => table.preBilled,
    ).length;
    const seatedGuests = floor.tables.reduce(
        (sum, table) => sum + (table.occupied ? table.covers : 0),
        0,
    );
    const floorRunningTotal = floor.tables
        .reduce(
            (sum, table) => sum + (table.occupied ? Number(table.total) : 0),
            0,
        )
        .toFixed(2);

    const visibleTables = floor.tables.filter((table) =>
        matchesFilter(table, filter),
    );

    const filterOptions: {
        value: TableFilter;
        label: string;
        count: number;
        dotClass?: string;
    }[] = [
        { value: 'all', label: 'All tables', count: totalCount },
        {
            value: 'available',
            label: 'Available',
            count: availableCount,
            dotClass: 'border border-foreground/35 bg-card',
        },
        {
            value: 'occupied',
            label: 'Seated',
            count: occupiedCount,
            dotClass: 'bg-brand-forest',
        },
        {
            value: 'prebilled',
            label: 'Pre-billed',
            count: preBilledCount,
            dotClass: 'bg-warning',
        },
    ];

    // Compute a centered bounding box so smaller layouts sit cleanly in the architectural stage.
    const mapPad = 32;
    const minX =
        floor.tables.length > 0
            ? Math.min(...floor.tables.map((table) => table.x))
            : 0;
    const minY =
        floor.tables.length > 0
            ? Math.min(...floor.tables.map((table) => table.y))
            : 0;
    const maxX =
        floor.tables.length > 0
            ? Math.max(...floor.tables.map((table) => table.x + table.width))
            : 320;
    const maxY =
        floor.tables.length > 0
            ? Math.max(...floor.tables.map((table) => table.y + table.height))
            : 240;
    const stageWidth = Math.max(320, maxX - minX + mapPad * 2);
    const stageHeight = Math.max(220, maxY - minY + mapPad * 2);

    return (
        <div className="flex min-h-0 flex-1 flex-col gap-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-3">
                    {floors.length > 1 ? (
                        <div
                            className="inline-flex items-center gap-0.5 rounded-xl bg-muted p-1"
                            role="group"
                            aria-label="Floors"
                        >
                            {floors.map((row) => {
                                const isActive = row.id === floor.id;
                                const rowOccupied = row.tables.filter(
                                    (table) => table.occupied,
                                ).length;

                                return (
                                    <button
                                        key={row.id}
                                        type="button"
                                        aria-pressed={isActive}
                                        onClick={() => {
                                            setActive(row.id);
                                            setFilter('all');
                                        }}
                                        className={cn(
                                            'inline-flex min-h-11 items-center gap-2 rounded-lg px-3.5 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-foreground',
                                            isActive
                                                ? 'bg-card font-semibold text-foreground shadow-xs'
                                                : 'text-muted-foreground hover:text-foreground',
                                        )}
                                    >
                                        <span>{row.name}</span>
                                        <span className="text-xs text-muted-foreground tabular-nums">
                                            {rowOccupied}/{row.tables.length}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    ) : (
                        <div className="flex flex-col gap-0.5">
                            <h2 className="font-heading text-xl font-bold tracking-tight text-foreground">
                                {floor.name}
                            </h2>
                            <p className="text-xs text-muted-foreground tabular-nums">
                                {occupiedCount} of {totalCount} tables seated
                                {seatedGuests > 0
                                    ? ` · ${seatedGuests} ${seatedGuests === 1 ? 'guest' : 'guests'}`
                                    : ''}
                            </p>
                        </div>
                    )}
                </div>

                <div className="flex flex-wrap items-center gap-1.5">
                    <Popover open={coversOpen} onOpenChange={setCoversOpen}>
                        <PopoverTrigger
                            render={
                                <Button
                                    type="button"
                                    variant="ghost"
                                    className="min-h-11 px-3 text-foreground tabular-nums hover:bg-card"
                                    aria-label={`Guest covers for new table: ${coverCount}`}
                                />
                            }
                        >
                            <Users
                                data-icon="inline-start"
                                aria-hidden="true"
                            />
                            {coverCount} {coverCount === 1 ? 'guest' : 'guests'}
                        </PopoverTrigger>
                        <PopoverContent align="end" className="w-64">
                            <PopoverHeader>
                                <PopoverTitle>
                                    Guests for next table
                                </PopoverTitle>
                            </PopoverHeader>
                            <div className="mt-2 flex flex-col gap-3">
                                <div className="grid grid-cols-4 gap-1.5">
                                    {COVER_PRESETS.map((preset) => (
                                        <Button
                                            key={preset}
                                            type="button"
                                            variant={
                                                coverCount === preset
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            className="min-h-11 tabular-nums"
                                            onClick={() => {
                                                setCovers(String(preset));
                                                setCoversOpen(false);
                                            }}
                                        >
                                            {preset}
                                        </Button>
                                    ))}
                                </div>
                                <div className="flex items-center gap-1.5 border-t pt-2.5">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        className="size-11"
                                        aria-label="Decrease guest count"
                                        disabled={coverCount <= 1}
                                        onClick={() =>
                                            setCovers(
                                                String(
                                                    Math.max(1, coverCount - 1),
                                                ),
                                            )
                                        }
                                    >
                                        <Minus aria-hidden="true" />
                                    </Button>
                                    <Input
                                        type="number"
                                        min={1}
                                        max={99}
                                        aria-label="Custom guest count"
                                        className="h-11 text-center tabular-nums"
                                        value={covers}
                                        onChange={(event) =>
                                            setCovers(event.target.value)
                                        }
                                    />
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        className="size-11"
                                        aria-label="Increase guest count"
                                        disabled={coverCount >= 99}
                                        onClick={() =>
                                            setCovers(
                                                String(
                                                    Math.min(
                                                        99,
                                                        coverCount + 1,
                                                    ),
                                                ),
                                            )
                                        }
                                    >
                                        <Plus aria-hidden="true" />
                                    </Button>
                                </div>
                            </div>
                        </PopoverContent>
                    </Popover>

                    <Button
                        type="button"
                        variant={qrMode ? 'default' : 'ghost'}
                        aria-pressed={qrMode}
                        className={cn(
                            'min-h-11 px-3',
                            !qrMode && 'text-foreground hover:bg-card',
                        )}
                        disabled={!trading}
                        onClick={() => setQrMode((value) => !value)}
                    >
                        <QrCode data-icon="inline-start" aria-hidden="true" />
                        Table QR
                    </Button>

                    <div
                        className="inline-flex items-center gap-0.5 rounded-xl bg-muted p-1"
                        role="group"
                        aria-label="Floor view mode"
                    >
                        <button
                            type="button"
                            aria-pressed={viewMode === 'grid'}
                            aria-label="Table cards view"
                            onClick={() => setViewMode('grid')}
                            className={cn(
                                'inline-flex min-h-11 items-center gap-1.5 rounded-lg px-3 text-xs font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-foreground',
                                viewMode === 'grid'
                                    ? 'bg-card font-semibold text-foreground shadow-xs'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            <LayoutGrid
                                className="size-3.5"
                                aria-hidden="true"
                            />
                            Cards
                        </button>
                        <button
                            type="button"
                            aria-pressed={viewMode === 'map'}
                            aria-label="Floor map view"
                            onClick={() => setViewMode('map')}
                            className={cn(
                                'inline-flex min-h-11 items-center gap-1.5 rounded-lg px-3 text-xs font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-foreground',
                                viewMode === 'map'
                                    ? 'bg-card font-semibold text-foreground shadow-xs'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            <Map className="size-3.5" aria-hidden="true" />
                            Map
                        </button>
                    </div>

                    {onNewCounterOrder && (
                        <Button
                            type="button"
                            className="min-h-11 px-4"
                            disabled={!trading}
                            onClick={onNewCounterOrder}
                        >
                            <Plus data-icon="inline-start" aria-hidden="true" />
                            Counter check
                        </Button>
                    )}
                </div>
            </div>

            <div className="flex flex-wrap items-center justify-between gap-2 border-b pb-3">
                <div
                    className="flex flex-wrap items-center gap-1.5"
                    role="group"
                    aria-label="Filter tables by status"
                >
                    {filterOptions.map((option) => {
                        const isSelected = filter === option.value;

                        return (
                            <button
                                key={option.value}
                                type="button"
                                aria-pressed={isSelected}
                                onClick={() => setFilter(option.value)}
                                className={cn(
                                    'inline-flex min-h-11 items-center gap-2 rounded-lg px-3 text-xs font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-foreground',
                                    isSelected
                                        ? 'bg-foreground font-semibold text-background'
                                        : 'bg-transparent text-muted-foreground hover:bg-card hover:text-foreground',
                                )}
                            >
                                {option.dotClass && (
                                    <span
                                        aria-hidden="true"
                                        className={cn(
                                            'size-2 shrink-0 rounded-full',
                                            isSelected
                                                ? option.value === 'available'
                                                    ? 'border border-background/70 bg-transparent'
                                                    : option.value ===
                                                        'prebilled'
                                                      ? 'bg-warning'
                                                      : 'bg-background'
                                                : option.dotClass,
                                        )}
                                    />
                                )}
                                <span>{option.label}</span>
                                <span
                                    className={cn(
                                        'tabular-nums',
                                        isSelected
                                            ? 'text-background/75'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {option.count}
                                </span>
                            </button>
                        );
                    })}
                </div>

                {occupiedCount > 0 && (
                    <div className="flex items-baseline gap-2 text-xs text-muted-foreground tabular-nums">
                        <span>Floor total</span>
                        <span className="font-heading text-sm font-bold text-foreground">
                            <MoneyText
                                amount={floorRunningTotal}
                                currency={currency}
                            />
                        </span>
                    </div>
                )}
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
                            className="min-h-11"
                            onClick={() => setQrMode(false)}
                        >
                            Cancel
                        </Button>
                    </AlertDescription>
                </Alert>
            )}

            {viewMode === 'grid' ? (
                visibleTables.length === 0 ? (
                    <div className="flex flex-1 items-center justify-center rounded-2xl border bg-card p-8">
                        <EmptyState
                            icon={LayoutGrid}
                            title="No matching tables"
                            description="No tables on this floor match the selected filter right now."
                            action={
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="min-h-11"
                                    onClick={() => setFilter('all')}
                                >
                                    Show all tables
                                </Button>
                            }
                        />
                    </div>
                ) : (
                    <div className="grid min-h-0 flex-1 grid-cols-1 content-start gap-3.5 overflow-y-auto pr-0.5 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                        {visibleTables.map((table) => {
                            const isOccupied = table.occupied;
                            const isPreBilled = table.preBilled;
                            const elapsed = formatElapsed(
                                table.openedAt,
                                nowMs,
                            );

                            return (
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
                                        'group flex min-h-44 flex-col justify-between rounded-2xl p-5 text-left transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-foreground disabled:opacity-50 motion-safe:active:translate-y-px',
                                        qrMode
                                            ? 'border-2 border-dashed border-primary bg-card text-foreground hover:bg-muted/60'
                                            : isPreBilled
                                              ? 'bg-warning text-warning-foreground shadow-sm hover:bg-warning/95'
                                              : isOccupied
                                                ? 'bg-brand-forest text-primary-foreground shadow-sm hover:bg-brand-forest/95'
                                                : 'border bg-card text-foreground shadow-xs hover:border-foreground/30 hover:bg-card/85',
                                    )}
                                >
                                    <div className="flex w-full items-start justify-between gap-3">
                                        <div className="flex min-w-0 flex-col gap-1">
                                            <span className="font-heading text-2xl leading-none font-bold tracking-tight">
                                                {table.code}
                                            </span>
                                            {table.label ? (
                                                <span
                                                    className={cn(
                                                        'truncate text-xs',
                                                        isPreBilled
                                                            ? 'text-warning-foreground/80'
                                                            : isOccupied
                                                              ? 'text-primary-foreground/80'
                                                              : 'text-muted-foreground',
                                                    )}
                                                >
                                                    {table.label}
                                                </span>
                                            ) : isOccupied ? (
                                                <span
                                                    className={cn(
                                                        'text-xs tabular-nums',
                                                        isPreBilled
                                                            ? 'text-warning-foreground/80'
                                                            : 'text-primary-foreground/75',
                                                    )}
                                                >
                                                    {table.seats} seats
                                                </span>
                                            ) : null}
                                        </div>

                                        {qrMode ? (
                                            <span className="inline-flex items-center gap-1.5 rounded-md bg-primary px-2.5 py-1 text-xs font-semibold text-primary-foreground">
                                                <QrCode
                                                    className="size-3.5"
                                                    aria-hidden="true"
                                                />
                                                Print QR
                                            </span>
                                        ) : isPreBilled ? (
                                            <span className="inline-flex items-center gap-1 rounded-md bg-warning-foreground/15 px-2.5 py-1 text-xs font-semibold text-warning-foreground">
                                                <Receipt
                                                    className="size-3.5"
                                                    aria-hidden="true"
                                                />
                                                Pre-billed
                                            </span>
                                        ) : isOccupied ? (
                                            <span className="inline-flex items-center rounded-md bg-primary-foreground/15 px-2.5 py-1 text-xs font-semibold text-primary-foreground tabular-nums">
                                                {table.multiBill
                                                    ? `${table.orderIds.length} bills`
                                                    : 'Seated'}
                                            </span>
                                        ) : (
                                            <span
                                                className={cn(
                                                    'inline-flex items-center gap-1.5 border border-border bg-muted/60 px-2.5 py-1 text-xs font-medium text-muted-foreground tabular-nums',
                                                    table.shape === 'round'
                                                        ? 'rounded-full'
                                                        : 'rounded-lg',
                                                )}
                                            >
                                                <Users
                                                    className="size-3"
                                                    aria-hidden="true"
                                                />
                                                {table.seats} seats
                                            </span>
                                        )}
                                    </div>

                                    {isOccupied ? (
                                        <div
                                            className={cn(
                                                'flex w-full items-end justify-between gap-2 border-t pt-3.5',
                                                isPreBilled
                                                    ? 'border-warning-foreground/15'
                                                    : 'border-primary-foreground/15',
                                            )}
                                        >
                                            <span className="font-heading text-xl leading-none font-bold tabular-nums">
                                                <MoneyText
                                                    amount={table.total}
                                                    currency={currency}
                                                />
                                            </span>
                                            <div
                                                className={cn(
                                                    'flex items-center gap-2.5 text-xs font-medium tabular-nums',
                                                    isPreBilled
                                                        ? 'text-warning-foreground/85'
                                                        : 'text-primary-foreground/85',
                                                )}
                                            >
                                                <span className="inline-flex items-center gap-1">
                                                    <Users
                                                        className="size-3.5"
                                                        aria-hidden="true"
                                                    />
                                                    {table.covers}
                                                </span>
                                                {elapsed !== '' && (
                                                    <span className="inline-flex items-center gap-1">
                                                        <Clock
                                                            className="size-3.5"
                                                            aria-hidden="true"
                                                        />
                                                        {elapsed}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                    ) : (
                                        <div className="flex w-full items-center justify-between gap-2 border-t pt-3.5 text-xs">
                                            <span className="font-medium text-muted-foreground">
                                                Available
                                            </span>
                                            <span className="inline-flex items-center gap-1 font-semibold text-foreground">
                                                Seat {coverCount}
                                                <ArrowRight
                                                    className="size-3.5 transition-transform motion-safe:group-hover:translate-x-0.5"
                                                    aria-hidden="true"
                                                />
                                            </span>
                                        </div>
                                    )}
                                </button>
                            );
                        })}
                    </div>
                )
            ) : (
                <div
                    className="flex min-h-96 flex-1 items-center justify-center overflow-auto rounded-2xl border bg-card p-6"
                    style={{
                        backgroundImage:
                            'radial-gradient(var(--border) 1px, transparent 1px)',
                        backgroundSize: '24px 24px',
                    }}
                >
                    <div
                        className="relative shrink-0"
                        style={{
                            width: stageWidth,
                            height: stageHeight,
                        }}
                    >
                        {floor.tables.map((table) => {
                            const isOccupied = table.occupied;
                            const isPreBilled = table.preBilled;
                            const matched = matchesFilter(table, filter);
                            const seatTone = qrMode
                                ? 'qr'
                                : isPreBilled
                                  ? 'prebilled'
                                  : isOccupied
                                    ? 'occupied'
                                    : 'available';

                            return (
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
                                        left: table.x - minX + mapPad,
                                        top: table.y - minY + mapPad,
                                        width: table.width,
                                        height: table.height,
                                    }}
                                    className={cn(
                                        'absolute flex flex-col items-center justify-center gap-1 p-2.5 text-center transition-all focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-foreground disabled:opacity-50 motion-safe:active:translate-y-px',
                                        table.shape === 'round'
                                            ? 'rounded-full'
                                            : 'rounded-2xl',
                                        !matched && 'opacity-25',
                                        qrMode
                                            ? 'border-2 border-dashed border-primary bg-card text-foreground shadow-xs hover:bg-muted'
                                            : isPreBilled
                                              ? 'bg-warning text-warning-foreground shadow-sm hover:bg-warning/95'
                                              : isOccupied
                                                ? 'bg-brand-forest text-primary-foreground shadow-sm hover:bg-brand-forest/95'
                                                : 'border bg-card text-foreground shadow-xs hover:border-foreground/35 hover:bg-muted/50',
                                    )}
                                >
                                    <MapSeatTicks
                                        seats={table.seats}
                                        tone={seatTone}
                                    />
                                    <span className="font-heading text-base leading-none font-bold tracking-tight">
                                        {table.code}
                                    </span>
                                    {qrMode ? (
                                        <span className="text-xs font-semibold text-foreground">
                                            Print QR
                                        </span>
                                    ) : isOccupied ? (
                                        <>
                                            <span className="text-xs leading-tight font-semibold tabular-nums">
                                                <MoneyText
                                                    amount={table.total}
                                                    currency={currency}
                                                />
                                            </span>
                                            <span
                                                className={cn(
                                                    'inline-flex items-center gap-1 text-xs leading-none tabular-nums',
                                                    isPreBilled
                                                        ? 'text-warning-foreground/85'
                                                        : 'text-primary-foreground/80',
                                                )}
                                            >
                                                <Users
                                                    className="size-3 shrink-0"
                                                    aria-hidden="true"
                                                />
                                                {table.covers} ·{' '}
                                                {formatElapsed(
                                                    table.openedAt,
                                                    nowMs,
                                                )}
                                            </span>
                                        </>
                                    ) : (
                                        <span className="text-xs text-muted-foreground tabular-nums">
                                            {table.seats} seats
                                        </span>
                                    )}
                                </button>
                            );
                        })}
                    </div>
                </div>
            )}

            <Dialog
                open={choosing !== null}
                onOpenChange={(isOpen) => !isOpen && setChoosing(null)}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            Table {choosing?.code} open checks
                        </DialogTitle>
                        <DialogDescription>
                            Choose which bill to open on this table.
                        </DialogDescription>
                    </DialogHeader>
                    {choosing !== null && (
                        <div className="flex flex-col gap-2">
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
                                        variant: 'outline',
                                        className:
                                            'h-auto min-h-14 justify-between px-4 py-3',
                                    })}
                                >
                                    <span className="flex flex-col items-start gap-0.5">
                                        <span className="font-heading font-semibold text-foreground">
                                            {check.orderNumber ??
                                                `Check #${check.id}`}
                                        </span>
                                        <span className="text-xs text-muted-foreground tabular-nums">
                                            Opened{' '}
                                            {new Date(
                                                check.openedAt,
                                            ).toLocaleTimeString([], {
                                                hour: '2-digit',
                                                minute: '2-digit',
                                            })}
                                        </span>
                                    </span>
                                    <span className="font-heading text-base font-semibold text-foreground tabular-nums">
                                        <MoneyText
                                            amount={check.total}
                                            currency={currency}
                                        />
                                    </span>
                                </Link>
                            ))}
                        </div>
                    )}
                    <DialogFooter>
                        {choosing !== null && (
                            <Button
                                type="button"
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
                        )}
                        <Button
                            type="button"
                            variant="ghost"
                            className="min-h-11"
                            onClick={() => setChoosing(null)}
                        >
                            Close
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
