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
import { undo } from '@/routes/reservations/folios/lines/tax-move';

type Row = App.Data.Folio.FolioRowData;

/**
 * Sends the collected taxes of one Move charges move back to the guest folio they came from, after a confirmation. The
 * count and the amount are the rows of the same move (`taxMoveGroup`) that can still go back; the server decides. A
 * refusal is an error toast.
 */
export function UndoTaxMoveDialog({
    reservationId,
    folioId,
    row,
    rows,
    currency,
    onClose,
}: {
    reservationId: number;
    folioId: number;
    row: Row | null;
    rows: Row[];
    currency: App.Data.Property.CurrencyData | null;
    onClose: () => void;
}) {
    const group = rows.filter(
        (other) =>
            row !== null &&
            other.taxMoveGroup === row.taxMoveGroup &&
            other.actions.includes('undo_tax_move'),
    );
    const amount = group
        .reduce((sum, other) => sum + Number(other.amount), 0)
        .toFixed(2);
    const lines =
        group.length === 1 ? '1 tax line' : `${group.length} tax lines`;

    return (
        <AlertDialog
            open={row !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Undo move of {row?.description ?? 'this tax'}?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        {lines} ·{' '}
                        <MoneyText amount={amount} currency={currency} /> go
                        back to the guest folio they came from, where the guest
                        pays them at check-in. Each line gets a reversal and
                        stays in the ledger.
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
