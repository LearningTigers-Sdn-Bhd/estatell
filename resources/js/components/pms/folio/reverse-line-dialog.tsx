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
import { reverse } from '@/routes/reservations/folios/lines';

/**
 * Reverses one charge after a confirmation. Its tax and discount lines reverse with it. A refusal is an error toast.
 */
export function ReverseLineDialog({
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
                        Reverse {row?.description}?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        A reversal line is posted for the charge and its tax and
                        discount lines. The original lines stay in the ledger.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                {row?.lineId != null && (
                    <Form
                        {...reverse.form({
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
                                    Reverse charge
                                </Button>
                            </AlertDialogFooter>
                        )}
                    </Form>
                )}
            </AlertDialogContent>
        </AlertDialog>
    );
}
