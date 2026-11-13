import { Form } from '@inertiajs/react';
import { MoneyText } from '@/components/pms/money-text';
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
import { undo } from '@/routes/reservations/folios/lines/credit-move';

/**
 * Undoes one credit move after a confirmation: both of its lines get a reversal, so the credit goes back to the folio
 * it came from. A refusal is an error toast.
 */
export function UndoCreditMoveDialog({
    reservationId,
    folioId,
    row,
    currency,
    onClose,
}: {
    reservationId: number;
    folioId: number;
    row: App.Data.Folio.FolioRowData | null;
    currency: App.Data.Property.CurrencyData | null;
    onClose: () => void;
}) {
    const amount = row?.amount.replace(/^-/, '') ?? '0.00';

    return (
        <AlertDialog
            open={row !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Undo this credit move?</AlertDialogTitle>
                    <AlertDialogDescription>
                        <MoneyText amount={amount} currency={currency} /> goes
                        back to the folio it came from, and the other folio owes
                        it again. Both lines get a reversal and stay in the
                        ledger.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                {row?.lineId != null && (
                    <Form
                        {...undo.form({
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
                                    Undo move
                                </Button>
                            </AlertDialogFooter>
                        )}
                    </Form>
                )}
            </AlertDialogContent>
        </AlertDialog>
    );
}
