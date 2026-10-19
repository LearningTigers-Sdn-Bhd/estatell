import { Link } from '@inertiajs/react';
import { BedDouble } from 'lucide-react';
import type { ReactNode } from 'react';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { StatusBadge } from '@/components/pms/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { show } from '@/routes/portal/bookings';

type Booking = App.Data.Portal.PortalRecentBookingData;

/**
 * The newest bookings of the company, for the portal dashboards. `showProperty` adds the property column on the
 * all-properties dashboard; `emptyAction` is the button under the empty state.
 */
export function PortalRecentBookings({
    bookings,
    showProperty = false,
    emptyAction,
}: {
    bookings: Booking[];
    showProperty?: boolean;
    emptyAction?: ReactNode;
}) {
    if (bookings.length === 0) {
        return (
            <EmptyState
                icon={BedDouble}
                title="No bookings yet"
                description="Stays booked for your company appear here."
                action={emptyAction}
            />
        );
    }

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    {showProperty && <TableHead>Property</TableHead>}
                    <TableHead>Our ref</TableHead>
                    <TableHead>Your ref</TableHead>
                    <TableHead>Booking</TableHead>
                    <TableHead>Stay</TableHead>
                    <TableHead>Status</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {bookings.map((booking) => (
                    <TableRow key={booking.reservationId}>
                        {showProperty && (
                            <TableCell>{booking.propertyName}</TableCell>
                        )}
                        <TableCell>
                            <Link
                                href={show({
                                    property: booking.propertyCode,
                                    reservation: booking.reservationId,
                                })}
                                className="font-medium tabular-nums underline-offset-4 hover:underline"
                            >
                                {booking.reference}
                            </Link>
                        </TableCell>
                        <TableCell>
                            {booking.externalReference ?? '—'}
                        </TableCell>
                        <TableCell>{booking.title}</TableCell>
                        <TableCell>
                            <span className="flex items-center gap-1">
                                <DateText date={booking.firstArrival} /> →{' '}
                                <DateText date={booking.lastDeparture} />
                            </span>
                        </TableCell>
                        <TableCell>
                            <StatusBadge status={booking.status} />
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}
