import { Head } from '@inertiajs/react';
import { LayoutGrid } from 'lucide-react';
import { EmptyState } from '@/components/pms/empty-state';
import {
    EventShell,
    eventBreadcrumbs,
} from '@/components/pms/events/event-shell';
import type { EventShellProps } from '@/components/pms/events/event-shell';

/**
 * Event page, Bookings tab. Facility bookings arrive with the facility bookings item; until then it is empty.
 */
export default function EventBookings(props: EventShellProps) {
    return (
        <>
            <Head title={`${props.event.name} · Bookings`} />
            <EventShell {...props} activeTab="bookings">
                <EmptyState
                    icon={LayoutGrid}
                    title="No facility bookings yet"
                    description="Bookings for halls, BBQ pits and activities will show here."
                />
            </EventShell>
        </>
    );
}

EventBookings.layout = (props: EventShellProps) => ({
    breadcrumbs: eventBreadcrumbs(props, 'bookings'),
});
