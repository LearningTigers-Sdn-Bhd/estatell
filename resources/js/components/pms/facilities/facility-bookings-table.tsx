import { router } from '@inertiajs/react';
import { Ban, Ellipsis, LayoutGrid, Pencil, Receipt } from 'lucide-react';
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
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { post as postNow } from '@/routes/facility-bookings';

type Booking = App.Data.Facilities.FacilityBookingRowData;

/** Adds the amounts of the live bookings, in cents, for the display-only total. */
function liveTotal(bookings: Booking[]): string {
    const cents = bookings
        .filter(
            (booking) =>
                booking.status.value !== 'cancelled' && booking.amount !== null,
        )
        .reduce(
            (sum, booking) => sum + Math.round(Number(booking.amount) * 100),
            0,
        );

    return (cents / 100).toFixed(2);
}

/**
 * The facility bookings of an event or a reservation, by date and time, Cancelled ones last and muted. Amount is the
 * price before tax; "Charge by hand" when the facility has no price. A reservation's table adds the Charge to column
 * and a total of the live bookings. A posted booking says "Posted" under its amount and can no longer be edited. Post
 * now (row menu) posts a Confirmed booking to its folio before its date. With no bookings the table keeps its header
 * and shows the empty state in one row. See docs/33-facilities/04-facility-bookings.md#posting and docs/33-facilities/04-facility-bookings.md#reservation-facilities-tab.
 */
export function FacilityBookingsTable({
    bookings,
    currency,
    emptyAction,
    emptyDescription = 'Book the hall, a BBQ pit or an activity for this event.',
    showChargeTo = false,
    onEdit,
    onCancel,
}: {
    bookings: Booking[];
    currency: App.Data.Property.CurrencyData | null;
    emptyAction?: ReactNode;
    emptyDescription?: string;
    showChargeTo?: boolean;
    onEdit: (booking: Booking) => void;
    onCancel: (booking: Booking) => void;
}) {
    const columns = showChargeTo ? 8 : 7;

    return (
        <div className="overflow-auto rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Facility</TableHead>
                        <TableHead>Date</TableHead>
                        <TableHead>Time</TableHead>
                        <TableHead className="text-right">Qty</TableHead>
                        {showChargeTo && <TableHead>Charge to</TableHead>}
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
                            <TableCell colSpan={columns}>
                                <EmptyState
                                    icon={LayoutGrid}
                                    title="No facility bookings yet"
                                    description={emptyDescription}
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
                            {showChargeTo && (
                                <TableCell className="max-w-56 truncate">
                                    {booking.chargeToLabel}
                                </TableCell>
                            )}
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
                                {booking.posted && (
                                    <p className="text-xs text-muted-foreground">
                                        Posted
                                    </p>
                                )}
                            </TableCell>
                            <TableCell>
                                <StatusBadge status={booking.status} />
                            </TableCell>
                            <TableCell>
                                {(booking.canEdit ||
                                    booking.canCancel ||
                                    booking.canPostNow) && (
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
                                            {booking.canPostNow && (
                                                <DropdownMenuItem
                                                    onClick={() =>
                                                        router.visit(
                                                            postNow({
                                                                facilityBooking:
                                                                    booking.id,
                                                            }),
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    <Receipt />
                                                    Post now
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
                {showChargeTo && bookings.length > 0 && (
                    <TableFooter>
                        <TableRow>
                            <TableCell colSpan={5} className="font-medium">
                                Total
                            </TableCell>
                            <TableCell className="text-right font-medium">
                                <MoneyText
                                    amount={liveTotal(bookings)}
                                    currency={currency}
                                    plain
                                />
                            </TableCell>
                            <TableCell colSpan={2} />
                        </TableRow>
                    </TableFooter>
                )}
            </Table>
        </div>
    );
}
