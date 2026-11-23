import { Head } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import {
    EventShell,
    eventBreadcrumbs,
} from '@/components/pms/events/event-shell';
import type { EventShellProps } from '@/components/pms/events/event-shell';
import { BookFacilitySheet } from '@/components/pms/facilities/book-facility-sheet';
import { CancelFacilityBookingDialog } from '@/components/pms/facilities/cancel-facility-booking-dialog';
import { FacilityBookingsTable } from '@/components/pms/facilities/facility-bookings-table';
import { Button } from '@/components/ui/button';

type Booking = App.Data.Facilities.FacilityBookingRowData;

type Props = EventShellProps & {
    bookings: Booking[];
    canBook: boolean;
    facilities: App.Data.Facilities.FacilityOptionData[];
    currency: App.Data.Property.CurrencyData | null;
};

/**
 * Event page, Bookings tab: the event's facility bookings, with Book facility and each row's Edit and Cancel. New
 * bookings take the event's status. See docs/33-facilities/04-facility-bookings.md#event-bookings-tab.
 */
export default function EventBookings(props: Props) {
    const { event, bookings, canBook, facilities, currency } = props;
    const [booking, setBooking] = useState(false);
    const [editing, setEditing] = useState<Booking | null>(null);
    const [cancelling, setCancelling] = useState<Booking | null>(null);

    const bookButton = canBook && facilities.length > 0 && (
        <Button onClick={() => setBooking(true)}>
            <Plus data-icon="inline-start" />
            Book facility
        </Button>
    );

    return (
        <>
            <Head title={`${event.name} · Bookings`} />
            <EventShell {...props} activeTab="bookings">
                <div className="flex flex-col gap-4">
                    {bookings.length > 0 && bookButton && (
                        <div className="flex justify-end">{bookButton}</div>
                    )}
                    <FacilityBookingsTable
                        bookings={bookings}
                        currency={currency}
                        emptyAction={bookButton || undefined}
                        onEdit={setEditing}
                        onCancel={setCancelling}
                    />
                </div>
            </EventShell>

            {canBook && (
                <BookFacilitySheet
                    open={booking || editing !== null}
                    onOpenChange={(open) => {
                        if (!open) {
                            setBooking(false);
                            setEditing(null);
                        }
                    }}
                    owner={{
                        type: 'event',
                        id: event.id,
                        startsOn: event.startsOn,
                        endsOn: event.endsOn,
                        chargeTo: `Event folio · ${event.payerName}`,
                    }}
                    facilities={facilities}
                    booking={editing ?? undefined}
                    currency={currency}
                />
            )}

            <CancelFacilityBookingDialog
                booking={cancelling}
                onClose={() => setCancelling(null)}
            />
        </>
    );
}

EventBookings.layout = (props: EventShellProps) => ({
    breadcrumbs: eventBreadcrumbs(props, 'bookings'),
});
