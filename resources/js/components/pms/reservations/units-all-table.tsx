import { Ship, Tag } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { DateText } from '@/components/pms/date-text';
import { MoneyText } from '@/components/pms/money-text';
import { PaxCount } from '@/components/pms/pax-count';
import { ChangeGroupPriceDialog } from '@/components/pms/reservations/change-group-price-dialog';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';

type Row = App.Data.Reservations.ReservationUnitRowData;

const PRICEABLE = ['confirmed', 'checked_in', 'checked_out'];

function roomText(row: Row, autoAssignRooms: boolean): string {
    return row.assigned ?? (autoAssignRooms ? 'At check-in' : 'Not assigned');
}

function nightsText(count: number): string {
    return `${count} ${count === 1 ? 'night' : 'nights'}`;
}

function Stay({ row }: { row: Row }) {
    if (row.stayLabel !== null) {
        return (
            <>
                <DateText date={row.arrival} /> · {row.stayLabel}
            </>
        );
    }

    return (
        <>
            <DateText date={row.arrival} /> → <DateText date={row.departure} />{' '}
            <span className="text-muted-foreground">
                · {nightsText(row.nightCount)}
            </span>
        </>
    );
}

/** The boat line under Stay: "In 06:00 boat · Out Own 11:30". Hidden on day use and with the Boats feature off. */
function BoatLine({ row }: { row: Row }) {
    return (
        <span className="flex items-center gap-1.5 text-xs text-muted-foreground tabular-nums">
            <Ship aria-hidden className="size-3.5 shrink-0" />
            In {row.boatIn ?? 'Not set'} · Out {row.boatOut ?? 'Not set'}
        </span>
    );
}

/**
 * Display sum of decimal strings, in cents, for the footer. The server sends each row's total.
 */
function sumTotals(rows: Row[]): string {
    const cents = rows.reduce(
        (sum, row) => sum + Math.round(Number(row.total) * 100),
        0,
    );

    return (cents / 100).toFixed(2);
}

/**
 * The All view of the Units tab: a toolbar (the All / Grouped toggle, Change price for the group and the summary
 * toggle), one table of every unit and a footer with the totals. A row click opens the unit in Grouped view. Below
 * `md` each row is a card.
 */
export function UnitsAllTable({
    reservationId,
    rows,
    units,
    toggle,
    summaryToggle,
    canChangePrice,
    hasBoats,
    autoAssignRooms,
    currency,
    onOpenUnit,
}: {
    reservationId: number;
    rows: Row[];
    /** The unit list, for the labels in the Change price Dialog. */
    units: App.Data.Reservations.ReservationUnitOptionData[];
    /** The All / Grouped toggle, on the left of the toolbar. */
    toggle: ReactNode;
    /** The hide summary button, on the right of the toolbar. */
    summaryToggle: ReactNode;
    /** Shows Change price (`reservations.change-price`). */
    canChangePrice: boolean;
    /** Shows the boat line under Stay (the Boats feature). */
    hasBoats: boolean;
    autoAssignRooms: boolean;
    currency: App.Data.Property.CurrencyData | null;
    onOpenUnit: (unitId: number) => void;
}) {
    const [changingPrice, setChangingPrice] = useState(false);
    const priceableIds = rows
        .filter(
            (row) => row.nightCount > 0 && PRICEABLE.includes(row.status.value),
        )
        .map((row) => row.id);
    const priceable = units
        .filter((unit) => priceableIds.includes(unit.id))
        .map((unit) => ({ id: unit.id, label: unit.label }));
    const roomNights = rows.reduce((sum, row) => sum + row.nightCount, 0);
    const summary = `${rows.length} ${rows.length === 1 ? 'unit' : 'units'} · ${roomNights} room ${roomNights === 1 ? 'night' : 'nights'}`;

    return (
        <div className="flex flex-col gap-4">
            <div className="flex items-center justify-between gap-3">
                {toggle}
                <div className="flex items-center gap-2">
                    {canChangePrice && priceable.length > 0 && (
                        <Button
                            variant="outline"
                            onClick={() => setChangingPrice(true)}
                        >
                            <Tag data-icon="inline-start" />
                            Change price
                        </Button>
                    )}
                    {summaryToggle}
                </div>
            </div>

            <div className="hidden rounded-lg border md:block">
                <Table>
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            <TableHead>Room</TableHead>
                            <TableHead>Guest</TableHead>
                            <TableHead>Pax</TableHead>
                            <TableHead>Stay</TableHead>
                            <TableHead>Rate plan</TableHead>
                            <TableHead className="text-right">
                                Room total
                            </TableHead>
                            <TableHead>Status</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((row) => (
                            <TableRow
                                key={row.id}
                                className="cursor-pointer"
                                onClick={() => onOpenUnit(row.id)}
                            >
                                <TableCell>
                                    <span className="text-muted-foreground">
                                        {row.roomType} ·
                                    </span>{' '}
                                    {roomText(row, autoAssignRooms)}
                                </TableCell>
                                <TableCell
                                    className={cn(
                                        'max-w-48 truncate',
                                        row.primaryGuest === null &&
                                            'text-muted-foreground',
                                    )}
                                >
                                    {row.primaryGuest ?? 'No primary guest'}
                                </TableCell>
                                <TableCell>
                                    <PaxCount stay={row} />
                                </TableCell>
                                <TableCell className="tabular-nums">
                                    <span className="flex flex-col gap-0.5">
                                        <span>
                                            <Stay row={row} />
                                        </span>
                                        {hasBoats && row.stayLabel === null && (
                                            <BoatLine row={row} />
                                        )}
                                    </span>
                                </TableCell>
                                <TableCell className="max-w-40 truncate">
                                    {row.ratePlan}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    <MoneyText
                                        amount={row.total}
                                        currency={currency}
                                    />
                                </TableCell>
                                <TableCell>
                                    <StatusBadge status={row.status} />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                    <TableFooter>
                        <TableRow className="hover:bg-transparent">
                            <TableCell colSpan={5}>{summary}</TableCell>
                            <TableCell className="text-right tabular-nums">
                                <MoneyText
                                    amount={sumTotals(rows)}
                                    currency={currency}
                                />
                            </TableCell>
                            <TableCell />
                        </TableRow>
                    </TableFooter>
                </Table>
            </div>

            <ul className="flex flex-col divide-y rounded-lg border md:hidden">
                {rows.map((row) => (
                    <li key={row.id}>
                        <button
                            type="button"
                            className="flex w-full min-w-0 flex-col gap-1 p-3 text-left text-sm"
                            onClick={() => onOpenUnit(row.id)}
                        >
                            <span className="flex items-center justify-between gap-2">
                                <span className="truncate font-medium">
                                    {row.roomType} ·{' '}
                                    {roomText(row, autoAssignRooms)}
                                </span>
                                <StatusBadge status={row.status} />
                            </span>
                            <span
                                className={cn(
                                    'truncate',
                                    row.primaryGuest === null &&
                                        'text-muted-foreground',
                                )}
                            >
                                {row.primaryGuest ?? 'No primary guest'}
                            </span>
                            <PaxCount stay={row} size="sm" />
                            <span className="text-muted-foreground tabular-nums">
                                <Stay row={row} />
                            </span>
                            {hasBoats && row.stayLabel === null && (
                                <BoatLine row={row} />
                            )}
                            <span className="flex items-center justify-between gap-2">
                                <span className="truncate text-muted-foreground">
                                    {row.ratePlan}
                                </span>
                                <MoneyText
                                    amount={row.total}
                                    currency={currency}
                                />
                            </span>
                        </button>
                    </li>
                ))}
                <li className="flex items-center justify-between gap-2 bg-muted/50 p-3 text-sm font-medium">
                    <span>{summary}</span>
                    <MoneyText amount={sumTotals(rows)} currency={currency} />
                </li>
            </ul>

            {canChangePrice && priceable.length > 0 && (
                <ChangeGroupPriceDialog
                    open={changingPrice}
                    onOpenChange={setChangingPrice}
                    reservationId={reservationId}
                    units={priceable}
                    currency={currency}
                />
            )}
        </div>
    );
}
