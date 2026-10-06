import { Form } from '@inertiajs/react';
import { useState } from 'react';
import { CreditOverrideField } from '@/components/pms/city-ledger/credit-override-field';
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
    canOverrideCredit,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    folio: App.Data.Folio.FolioData;
    currency: App.Enums.Currency | null;
    canOverrideCredit: boolean;
}) {
    const [reason, setReason] = useState('');

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
                    {({ processing, errors }) => (
                        <>
                            <CreditOverrideField
                                id="settle-credit-reason"
                                error={errors.credit_override_reason}
                                canOverride={canOverrideCredit}
                                value={reason}
                                onChange={setReason}
                            />
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
                        </>
                    )}
                </Form>
            </AlertDialogContent>
        </AlertDialog>
    );
}
