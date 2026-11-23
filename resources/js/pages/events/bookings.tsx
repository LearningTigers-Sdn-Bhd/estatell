import { Form, Head } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import {
    EventShell,
    eventBreadcrumbs,
} from '@/components/pms/events/event-shell';
import type { EventShellProps } from '@/components/pms/events/event-shell';
import { BookFacilitySheet } from '@/components/pms/facilities/book-facility-sheet';
import { FacilityBookingsTable } from '@/components/pms/facilities/facility-bookings-table';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cancel } from '@/routes/facility-bookings';

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
                    eventId={event.id}
                    startsOn={event.startsOn}
                    endsOn={event.endsOn}
                    chargeTo={`Event folio · ${event.payerName}`}
                    facilities={facilities}
                    booking={editing ?? undefined}
                    currency={currency}
                />
            )}

            <AlertDialog
                open={cancelling !== null}
                onOpenChange={(open) => !open && setCancelling(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Cancel {cancelling?.facilityName} booking?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            The facility becomes free for others at that time.
                            To book it again, make a new booking.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    {cancelling && (
                        <Form
                            {...cancel.form({ facilityBooking: cancelling.id })}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setCancelling(null)}
                        >
                            {({ processing }) => (
                                <AlertDialogFooter>
                                    <AlertDialogCancel>
                                        Keep booking
                                    </AlertDialogCancel>
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        disabled={processing}
                                        focusableWhenDisabled
                                    >
                                        {processing && (
                                            <Spinner data-icon="inline-start" />
                                        )}
                                        Cancel booking
                                    </Button>
                                </AlertDialogFooter>
                            )}
                        </Form>
                    )}
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

EventBookings.layout = (props: EventShellProps) => ({
    breadcrumbs: eventBreadcrumbs(props, 'bookings'),
});
