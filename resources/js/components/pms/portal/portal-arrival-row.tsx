import { Link } from '@inertiajs/react';
import { Eye } from 'lucide-react';
import { DateText } from '@/components/pms/date-text';
import { buttonVariants } from '@/components/ui/button';
import { show } from '@/routes/portal/bookings';

type Arrival = App.Data.Portal.PortalArrivalData;

/**
 * One upcoming arrival on a portal dashboard: guest or group, references, date and a link to the booking.
 * `showProperty` adds the property name on the all-properties dashboard.
 */
export function PortalArrivalRow({
    arrival,
    showProperty = false,
}: {
    arrival: Arrival;
    showProperty?: boolean;
}) {
    const details = [
        showProperty ? arrival.propertyName : null,
        arrival.reference,
        arrival.externalReference,
    ].filter(Boolean);

    return (
        <li className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-4">
            <div className="flex min-w-0 flex-col gap-1">
                <span className="truncate font-medium">{arrival.title}</span>
                <span className="truncate text-sm text-muted-foreground">
                    {details.join(' · ')}
                </span>
            </div>
            <div className="flex items-center gap-4 text-sm">
                <DateText date={arrival.arrivalDate} />
                <Link
                    href={show({
                        property: arrival.propertyCode,
                        reservation: arrival.reservationId,
                    })}
                    className={buttonVariants({
                        variant: 'outline',
                        size: 'sm',
                    })}
                >
                    <Eye data-icon="inline-start" />
                    View
                </Link>
            </div>
        </li>
    );
}
