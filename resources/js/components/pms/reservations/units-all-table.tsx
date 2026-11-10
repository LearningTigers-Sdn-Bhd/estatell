import { Plus } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { BulkActionBar } from '@/components/pms/bulk-action-bar';
import { AddAddOnDialog } from '@/components/pms/reservations/add-add-on-dialog';
import { DateText } from '@/components/pms/date-text';
import { MoneyText } from '@/components/pms/money-text';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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

const OPEN = ['confirmed', 'checked_in'];

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
 * The All view of the Units tab: one table of every unit, a footer with the totals, and tick boxes for the group
 * actions in the bulk bar. A row click opens the unit in Grouped view. Below `md` each row is a card.
 */
export function UnitsAllTable({
    reservationId,
    rows,
    units,
    toggle,
    canSelect,
    canManage,
    chargeItems,
    autoAssignRooms,
    currency,
    onOpenUnit,
}: {
    reservationId: number;
    rows: Row[];
    /** The unit list, for the add-on Dialog. */
    units: App.Data.Reservations.ReservationUnitOptionData[];
    /** Shows Add add-on in the bulk bar (`reservations.manage`). */
    canManage: boolean;
    chargeItems?: App.Data.Reservations.ChargeItemOptionData[];
    /** The All / Grouped toggle, shown above the table. */
    toggle: ReactNode;
    canSelect: boolean;
    autoAssignRooms: boolean;
    currency: App.Data.Property.CurrencyData | null;
    onOpenUnit: (unitId: number) => void;
}) {
    const [selected, setSelected] = useState<number[]>([]);
    const [addingAddOn, setAddingAddOn] = useState(false);
    const openUnits = units.filter((unit) => OPEN.includes(unit.status.value));
    const selectable = rows
        .filter((row) => row.selectable)
        .map((row) => row.id);
    const ticked = selected.filter((id) => selectable.includes(id));
    const allTicked =
        selectable.length > 0 && ticked.length === selectable.length;
    const roomNights = rows.reduce((sum, row) => sum + row.nightCount, 0);
    const toggleRow = (id: number, checked: boolean) =>
        setSelected((current) =>
            checked
                ? [...current.filter((one) => one !== id), id]
                : current.filter((one) => one !== id),
        );
    const summary = `${rows.length} ${rows.length === 1 ? 'unit' : 'units'} · ${roomNights} room ${roomNights === 1 ? 'night' : 'nights'}`;

    return (
        <div className="flex min-h-full flex-col gap-4">
            <div className="flex items-center justify-between gap-3">
                {toggle}
            </div>

            <div className="hidden rounded-lg border md:block">
                <Table>
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            {canSelect && (
                                <TableHead className="w-10">
                                    <Checkbox
                                        aria-label="Tick every unit"
                                        checked={allTicked}
                                        indeterminate={
                                            ticked.length > 0 && !allTicked
                                        }
                                        disabled={selectable.length === 0}
                                        onCheckedChange={(checked) =>
                                            setSelected(
                                                checked ? selectable : [],
                                            )
                                        }
                                    />
                                </TableHead>
                            )}
                            <TableHead>Room</TableHead>
                            <TableHead>Guest</TableHead>
                            <TableHead className="text-right">Pax</TableHead>
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
                                data-state={
                                    ticked.includes(row.id)
                                        ? 'selected'
                                        : undefined
                                }
                                className="cursor-pointer"
                                onClick={() => onOpenUnit(row.id)}
                            >
                                {canSelect && (
                                    <TableCell
                                        onClick={(event) =>
                                            event.stopPropagation()
                                        }
                                    >
                                        {row.selectable && (
                                            <Checkbox
                                                aria-label={`Tick ${row.roomType} · ${roomText(row, autoAssignRooms)}`}
                                                checked={ticked.includes(
                                                    row.id,
                                                )}
                                                onCheckedChange={(checked) =>
                                                    toggleRow(row.id, checked)
                                                }
                                            />
                                        )}
                                    </TableCell>
                                )}
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
                                <TableCell className="text-right tabular-nums">
                                    {row.adults}/{row.children}
                                </TableCell>
                                <TableCell className="tabular-nums">
                                    <Stay row={row} />
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
                            {canSelect && <TableCell />}
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
                    <li key={row.id} className="flex items-start gap-3 p-3">
                        {canSelect && (
                            <span className="pt-0.5">
                                {row.selectable ? (
                                    <Checkbox
                                        aria-label={`Tick ${row.roomType} · ${roomText(row, autoAssignRooms)}`}
                                        checked={ticked.includes(row.id)}
                                        onCheckedChange={(checked) =>
                                            toggleRow(row.id, checked)
                                        }
                                    />
                                ) : (
                                    <span className="block size-4" />
                                )}
                            </span>
                        )}
                        <button
                            type="button"
                            className="flex min-w-0 flex-1 flex-col gap-1 text-left text-sm"
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
                                {row.primaryGuest ?? 'No primary guest'} ·{' '}
                                {row.adults}/{row.children}
                            </span>
                            <span className="text-muted-foreground tabular-nums">
                                <Stay row={row} />
                            </span>
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

            <BulkActionBar
                count={ticked.length}
                onClear={() => setSelected([])}
                stackOnMobile
            >
                {canManage && openUnits.length > 0 && (
                    <Button
                        variant="outline"
                        onClick={() => setAddingAddOn(true)}
                    >
                        <Plus data-icon="inline-start" />
                        Add add-on
                    </Button>
                )}
            </BulkActionBar>
            {canManage && openUnits.length > 0 && (
                <AddAddOnDialog
                    open={addingAddOn}
                    onOpenChange={setAddingAddOn}
                    reservationId={reservationId}
                    units={openUnits}
                    initialUnitIds={ticked}
                    chargeItems={chargeItems}
                    currency={currency}
                />
            )}
        </div>
    );
}
