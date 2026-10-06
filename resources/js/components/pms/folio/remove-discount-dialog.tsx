import { Form } from '@inertiajs/react';
import { KEEP_OPTIONS } from '@/components/pms/use-form-options';
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
import { destroy } from '@/routes/reservations/folios/discounts';

/**
 * Removes a discount from its charge after a confirmation. The charge stays. A refusal is an error toast.
 */
export function RemoveDiscountDialog({
    reservationId,
    folioId,
    row,
    onClose,
}: {
    reservationId: number;
    folioId: number;
    row: App.Data.Folio.FolioRowData | null;
    onClose: () => void;
}) {
    return (
        <AlertDialog
            open={row !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Remove {row?.description}?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        The discount and its tax lines are reversed. The charge
                        stays, and the same discount cannot be applied to it
                        again.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                {row?.lineId != null && (
                    <Form
                        {...destroy.form({
                            reservation: reservationId,
                            folio: folioId,
                            line: row.lineId,
                        })}
                        options={{ preserveScroll: true, except: KEEP_OPTIONS }}
                        onSuccess={onClose}
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
                                    Remove discount
                                </Button>
                            </AlertDialogFooter>
                        )}
                    </Form>
                )}
            </AlertDialogContent>
        </AlertDialog>
    );
}
