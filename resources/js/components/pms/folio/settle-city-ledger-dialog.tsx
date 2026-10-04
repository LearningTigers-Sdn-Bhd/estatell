import { Form } from '@inertiajs/react';
import { MoneyText } from '@/components/pms/money-text';
import { KEEP_OPTIONS } from '@/components/pms/reservations/use-form-options';
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
import { cityLedger } from '@/routes/reservations/folios';

/**
 * Moves the whole balance of a folio to the city ledger after a confirmation. The folio closes and an AR invoice is
 * issued to the company that pays it. A refusal is an error toast.
 */
export function SettleCityLedgerDialog({
    open,
    onOpenChange,
    reservationId,
    folio,
    currency,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    folio: App.Data.Folio.FolioData;
    currency: App.Enums.Currency | null;
}) {
    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Settle {folio.name} to City ledger?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        {folio.payerCompanyName} will owe{' '}
                        <MoneyText
                            amount={folio.balance ?? '0.00'}
                            currency={currency}
                        />
                        . The folio closes and an invoice is issued. You cannot
                        reopen the folio unless the invoice is voided.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <Form
                    {...cityLedger.form({
                        reservation: reservationId,
                        folio: Number(folio.key),
                    })}
                    options={{ preserveScroll: true, except: KEEP_OPTIONS }}
                    onSuccess={() => onOpenChange(false)}
                >
                    {({ processing }) => (
                        <AlertDialogFooter>
                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                            <Button
                                type="submit"
                                disabled={processing}
                                focusableWhenDisabled
                            >
                                {processing && (
                                    <Spinner data-icon="inline-start" />
                                )}
                                Settle to City ledger
                            </Button>
                        </AlertDialogFooter>
                    )}
                </Form>
            </AlertDialogContent>
        </AlertDialog>
    );
}
