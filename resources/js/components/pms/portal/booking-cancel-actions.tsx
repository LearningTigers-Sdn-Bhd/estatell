import { router } from '@inertiajs/react';
import { useState } from 'react';
import { DateText } from '@/components/pms/date-text';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cancel, cancellationRequest } from '@/routes/portal/bookings';

/**
 * Cancel the whole booking free (before the company's cut-off), or ask the property to cancel it (after the cut-off, or
 * when the company has none). Changes to some rooms go through the property.
 */
export function BookingCancelActions({
    propertyCode,
    booking,
}: {
    propertyCode: string;
    booking: App.Data.Portal.PortalBookingData;
}) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const key = { property: propertyCode, reservation: booking.id };

    const send = (url: string, onDone?: () => void) => {
        router.post(
            url,
            {},
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => {
                    setProcessing(false);
                    onDone?.();
                },
            },
        );
    };

    if (booking.cancelMode === 'closed') {
        return null;
    }

    if (booking.cancelMode === 'request' && booking.cancellationRequested) {
        return (
            <p className="text-sm text-muted-foreground">
                You asked the property to cancel this booking
                {booking.cancellationRequestedAt && (
                    <>
                        {' '}
                        on <DateText date={booking.cancellationRequestedAt} />
                    </>
                )}
                . The property will confirm any charge.
            </p>
        );
    }

    if (booking.cancelMode === 'request') {
        return (
            <AlertDialog open={open} onOpenChange={setOpen}>
                <AlertDialogTrigger render={<Button variant="outline" />}>
                    Request cancellation
                </AlertDialogTrigger>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Ask to cancel {booking.reference}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Free cancellation has ended for this booking. The
                            property decides on any charge and cancels the
                            booking for you.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Keep booking</AlertDialogCancel>
                        <Button
                            type="button"
                            variant="destructive"
                            disabled={processing}
                            focusableWhenDisabled
                            onClick={() =>
                                send(cancellationRequest.url(key), () =>
                                    setOpen(false),
                                )
                            }
                        >
                            {processing && <Spinner data-icon="inline-start" />}
                            Request cancellation
                        </Button>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        );
    }

    return (
        <AlertDialog open={open} onOpenChange={setOpen}>
            <AlertDialogTrigger render={<Button variant="outline" />}>
                Cancel booking
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Cancel {booking.reference}?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        All rooms of this booking are cancelled with no charge.
                        This cannot be undone.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Keep booking</AlertDialogCancel>
                    <Button
                        type="button"
                        variant="destructive"
                        disabled={processing}
                        focusableWhenDisabled
                        onClick={() =>
                            send(cancel.url(key), () => setOpen(false))
                        }
                    >
                        {processing && <Spinner data-icon="inline-start" />}
                        Cancel booking
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
