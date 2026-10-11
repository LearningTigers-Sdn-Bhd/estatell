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
import { voidInvoiceAndReopen } from '@/routes/reservations/folios';

/**
 * Voids the folio's AR invoice and reopens the folio after a confirmation. The City ledger line stays on the folio.
 * A refusal rolls both back and is an error toast.
 */
export function VoidInvoiceAndReopenDialog({
    reservationId,
    folioId,
    invoiceNumber,
    open,
    onOpenChange,
}: {
    reservationId: number;
    folioId: number;
    invoiceNumber: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Void invoice {invoiceNumber} and reopen this folio?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        The City ledger line stays on the folio until you
                        reverse it and settle again.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <Form
                    {...voidInvoiceAndReopen.form({
                        reservation: reservationId,
                        folio: folioId,
                    })}
                    options={{ preserveScroll: true, except: KEEP_OPTIONS }}
                    onSuccess={() => onOpenChange(false)}
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
                                Void and reopen
                            </Button>
                        </AlertDialogFooter>
                    )}
                </Form>
            </AlertDialogContent>
        </AlertDialog>
    );
}
