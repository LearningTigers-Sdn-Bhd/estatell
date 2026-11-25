import { Link, router } from '@inertiajs/react';
import { Users } from 'lucide-react';
import { useRef, useState } from 'react';
import { MoneyText } from '@/components/pms/money-text';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { terminal } from '@/routes/pos';
import { store as storeOrder } from '@/routes/pos/orders';
import { qr as tableQr } from '@/routes/pos/tables';
import type { PosCurrency } from './pos-types';

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
};

export type Floor = { id: number; name: string; tables: FloorTable[] };

function minutesSince(iso: string | null): string {
    if (iso === null) {
        return '';
    }

    const minutes = Math.max(
        0,
        Math.round((Date.now() - new Date(iso).getTime()) / 60000),
    );

    return minutes < 60
        ? `${minutes} min`
        : `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
}

/**
 * The pay-later landing: the floor plan with a live tile per table. A vacant table opens a check, an occupied one opens its
 * check, and a table with two or more checks asks which. Amounts are the server's.
 */
export function PosFloorBoard({
    floors,
    outletId,
    registerId,
    diningOptionId,
    currency,
    trading,
}: {
    floors: Floor[];
    outletId: number;
    registerId: number;
    diningOptionId: number;
    currency: PosCurrency;
    trading: boolean;
}) {
    const [active, setActive] = useState(floors[0]?.id ?? 0);
    const [covers, setCovers] = useState('2');
    const [choosing, setChoosing] = useState<FloorTable | null>(null);
    const pressTimer = useRef<number | null>(null);
    const longPressed = useRef(false);
    const floor = floors.find((row) => row.id === active) ?? floors[0];

    if (floor === undefined) {
        return (
            <p className="text-sm text-muted-foreground">
                No floor plan yet. Add floors and tables under Settings, POS,
                Floor plan.
            </p>
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
                        size="sm"
                        variant={row.id === floor.id ? 'default' : 'outline'}
                        onClick={() => setActive(row.id)}
                    >
                        {row.name}
                    </Button>
                ))}
                <label className="ml-auto flex items-center gap-2 text-sm">
                    Covers for a new check
                    <Input
                        type="number"
                        min={1}
                        max={99}
                        className="w-20"
                        value={covers}
                        onChange={(event) => setCovers(event.target.value)}
                    />
                </label>
            </div>

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
                                    {minutesSince(table.openedAt)}
                                </span>
                                {table.multiBill && (
                                    <Badge variant="secondary">
                                        {table.orderIds.length} bills
                                    </Badge>
                                )}
                                {table.preBilled && (
                                    <Badge variant="outline">Pre-bill</Badge>
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

            {choosing !== null && (
                <div className="flex flex-wrap items-center gap-2 rounded-lg border p-3">
                    <span className="text-sm font-medium">
                        Table {choosing.code} has {choosing.orderIds.length}{' '}
                        checks. Open:
                    </span>
                    {choosing.orderIds.map((id) => (
                        <Link
                            key={id}
                            href={terminal.url(
                                { outlet: outletId },
                                { query: { register: registerId, order: id } },
                            )}
                            className={buttonVariants({
                                variant: 'secondary',
                                size: 'sm',
                            })}
                        >
                            Check #{id}
                        </Link>
                    ))}
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => setChoosing(null)}
                    >
                        Cancel
                    </Button>
                </div>
            )}
        </div>
    );
}
