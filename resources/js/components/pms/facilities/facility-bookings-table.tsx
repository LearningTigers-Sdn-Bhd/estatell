import { Ban, Ellipsis, LayoutGrid, Pencil } from 'lucide-react';
import type { ReactNode } from 'react';
import { formatDate } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { MoneyText } from '@/components/pms/money-text';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

type Booking = App.Data.Facilities.FacilityBookingRowData;

/**
 * The facility bookings of an event, by date and time, Cancelled ones last and muted. Amount is the price before tax;
 * "Charge by hand" when the facility has no price. With no bookings the table keeps its header and shows the empty
 * state in one row. See docs/33-facilities/04-facility-bookings.md#event-bookings-tab.
 */
export function FacilityBookingsTable({
    bookings,
    currency,
    emptyAction,
    onEdit,
    onCancel,
}: {
    bookings: Booking[];
    currency: App.Data.Property.CurrencyData | null;
    emptyAction?: ReactNode;
    onEdit: (booking: Booking) => void;
    onCancel: (booking: Booking) => void;
}) {
    return (
        <div className="overflow-auto rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Facility</TableHead>
                        <TableHead>Date</TableHead>
                        <TableHead>Time</TableHead>
                        <TableHead className="text-right">Qty</TableHead>
                        <TableHead className="text-right">
                            Amount{currency ? ` (${currency.code})` : ''}
                        </TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead className="w-12">
                            <span className="sr-only">Actions</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {bookings.length === 0 && (
                        <TableRow className="hover:bg-transparent">
                            <TableCell colSpan={7}>
                                <EmptyState
                                    icon={LayoutGrid}
                                    title="No facility bookings yet"
                                    description="Book the hall, a BBQ pit or an activity for this event."
                                    action={emptyAction}
                                />
                            </TableCell>
                        </TableRow>
                    )}
                    {bookings.map((booking) => (
                        <TableRow
                            key={booking.id}
                            className={
                                booking.status.value === 'cancelled'
                                    ? 'text-muted-foreground'
                                    : undefined
                            }
                        >
                            <TableCell className="font-medium">
                                {booking.facilityName}
                                {booking.notes && (
                                    <p className="max-w-64 truncate text-xs font-normal text-muted-foreground">
                                        {booking.notes}
                                    </p>
                                )}
                            </TableCell>
                            <TableCell className="tabular-nums">
                                {formatDate(booking.bookingDate)}
                            </TableCell>
                            <TableCell className="tabular-nums">
                                {booking.startsAt}–{booking.endsAt}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {booking.quantity} {booking.capacityUnitLabel}
                            </TableCell>
                            <TableCell className="text-right">
                                {booking.amount === null ? (
                                    <span className="text-muted-foreground">
                                        Charge by hand
                                    </span>
                                ) : (
                                    <MoneyText
                                        amount={booking.amount}
                                        currency={currency}
                                        plain
                                    />
                                )}
                            </TableCell>
                            <TableCell>
                                <StatusBadge status={booking.status} />
                            </TableCell>
                            <TableCell>
                                {(booking.canEdit || booking.canCancel) && (
                                    <DropdownMenu>
                                        <DropdownMenuTrigger
                                            render={
                                                <Button
                                                    variant="ghost"
                                                    size="icon-sm"
                                                />
                                            }
                                            aria-label={`Actions for ${booking.facilityName} on ${formatDate(booking.bookingDate)}`}
                                        >
                                            <Ellipsis />
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent
                                            align="end"
                                            className="w-44"
                                        >
                                            {booking.canEdit && (
                                                <DropdownMenuItem
                                                    onClick={() =>
                                                        onEdit(booking)
                                                    }
                                                >
                                                    <Pencil />
                                                    Edit
                                                </DropdownMenuItem>
                                            )}
                                            {booking.canCancel && (
                                                <DropdownMenuItem
                                                    variant="destructive"
                                                    onClick={() =>
                                                        onCancel(booking)
                                                    }
                                                >
                                                    <Ban />
                                                    Cancel booking
                                                </DropdownMenuItem>
                                            )}
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                )}
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}
