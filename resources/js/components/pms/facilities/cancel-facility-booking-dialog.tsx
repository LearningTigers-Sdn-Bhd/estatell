import { Form } from '@inertiajs/react';
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

/**
 * Confirms cancelling one facility booking, on the event Bookings tab and the reservation Facilities tab.
 * See docs/33-facilities/04-facility-bookings.md#actions.
 */
export function CancelFacilityBookingDialog({
    booking,
    onClose,
}: {
    booking: Booking | null;
    onClose: () => void;
}) {
    return (
        <AlertDialog
            open={booking !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Cancel {booking?.facilityName} booking?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        The facility becomes free for others at that time. To
                        book it again, make a new booking.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                {booking && (
                    <Form
                        {...cancel.form({ facilityBooking: booking.id })}
                        options={{ preserveScroll: true }}
                        onSuccess={onClose}
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
    );
}
