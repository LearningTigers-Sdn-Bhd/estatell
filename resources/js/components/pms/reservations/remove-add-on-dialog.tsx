import { Form } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useState } from 'react';
import { KEEP_OPTIONS } from '@/components/pms/use-form-options';
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
import { destroy } from '@/routes/reservations/units/add-ons';

/**
 * Removes one add-on row after a confirmation. A refusal comes back as an error toast.
 */
export function RemoveAddOnDialog({
    reservationId,
    addOn,
}: {
    reservationId: number;
    addOn: App.Data.Reservations.ReservationAddOnData;
}) {
    const [open, setOpen] = useState(false);

    return (
        <AlertDialog open={open} onOpenChange={setOpen}>
            <AlertDialogTrigger
                render={
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        aria-label={`Remove ${addOn.chargeItem}`}
                    />
                }
            >
                <Trash2 />
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Remove {addOn.chargeItem}?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        It is taken off {addOn.unitLabel}. This cannot be
                        undone, but you can add it again.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <Form
                    {...destroy.form({
                        reservation: reservationId,
                        unit: addOn.unitId,
                        addOn: addOn.id,
                    })}
                    options={{ preserveScroll: true, except: KEEP_OPTIONS }}
                    onSuccess={() => setOpen(false)}
                >
                    {({ processing }) => (
                        <AlertDialogFooter>
                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={processing}
                                focusableWhenDisabled
                            >
                                {processing && (
                                    <Spinner data-icon="inline-start" />
                                )}
                                Remove add-on
                            </Button>
                        </AlertDialogFooter>
                    )}
                </Form>
            </AlertDialogContent>
        </AlertDialog>
    );
}
