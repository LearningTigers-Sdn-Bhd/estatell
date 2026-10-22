import { Form } from '@inertiajs/react';
import { useState } from 'react';
import { MoneyText } from '@/components/pms/money-text';
import { KEEP_OPTIONS } from '@/components/pms/use-form-options';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { useRetainedValue } from '@/components/pms/use-retained-value';
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
import { voidMethod } from '@/routes/reservations/folios/payments';

type Row = App.Data.Folio.FolioRowData;

function VoidForm({
    open,
    onClose,
    reservationId,
    folioId,
    row,
    currency,
}: {
    open: boolean;
    onClose: () => void;
    reservationId: number;
    folioId: number;
    row: Row;
    currency: App.Data.Property.CurrencyData | null;
}) {
    const [key] = useState(() => crypto.randomUUID());

    return (
        <AlertDialog open={open} onOpenChange={(next) => !next && onClose()}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Void {row.description}?</AlertDialogTitle>
                    <AlertDialogDescription>
                        All of{' '}
                        <MoneyText amount={row.amount} currency={currency} />{' '}
                        goes back to the same method. Use this only for a
                        payment recorded by mistake today. A surcharge stays:
                        reverse it on its own if needed.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <Form
                    {...voidMethod.form({
                        reservation: reservationId,
                        folio: folioId,
                        payment: row.paymentId ?? 0,
                    })}
                    transform={(data) => ({ ...data, idempotency_key: key })}
                    options={{ preserveScroll: true, except: KEEP_OPTIONS }}
                    onSuccess={onClose}
                >
                    {({ processing, errors }) => (
                        <>
                            {errors.form && (
                                <p className="pb-2 text-sm text-destructive">
                                    {errors.form}
                                </p>
                            )}
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
                                    Void payment
                                </Button>
                            </AlertDialogFooter>
                        </>
                    )}
                </Form>
            </AlertDialogContent>
        </AlertDialog>
    );
}

/**
 * Voids a payment taken today by mistake after a confirmation: all of it goes back to its own method. A refusal (an
 * older payment, a payment that already has a refund) shows in the Dialog.
 */
export function VoidPaymentDialog({
    reservationId,
    folioId,
    row,
    currency,
    onClose,
}: {
    reservationId: number;
    folioId: number;
    row: Row | null;
    currency: App.Data.Property.CurrencyData | null;
    onClose: () => void;
}) {
    const open = row !== null;
    const kept = useRetainedValue(row);
    const fresh = useFreshOnOpen(open);

    if (kept === null) {
        return null;
    }

    return (
        <VoidForm
            key={`${kept.lineId}-${fresh}`}
            open={open}
            onClose={onClose}
            reservationId={reservationId}
            folioId={folioId}
            row={kept}
            currency={currency}
        />
    );
}
