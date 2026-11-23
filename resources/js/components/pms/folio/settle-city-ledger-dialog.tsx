import { Form } from '@inertiajs/react';
import { AlertCircleIcon } from 'lucide-react';
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
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { folioRoutes } from '@/components/pms/folio/folio-owner';
import type { FolioOwner } from '@/components/pms/folio/folio-owner';

/**
 * Moves the whole balance of a folio to the city ledger after a confirmation. The folio closes and an AR invoice is
 * issued to the company that pays it. A company over its credit limit gets a warning, and the invoice is still issued.
 */
export function SettleCityLedgerDialog({
    open,
    onOpenChange,
    owner,
    folio,
    currency,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    owner: FolioOwner;
    folio: Pick<
        App.Data.Folio.FolioData,
        'key' | 'name' | 'balance' | 'payerCompanyName' | 'settleCreditWarning'
    >;
    currency: App.Data.Property.CurrencyData | null;
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
                    {...folioRoutes(owner).cityLedger(Number(folio.key)).form}
                    options={{ preserveScroll: true, except: KEEP_OPTIONS }}
                    onSuccess={() => onOpenChange(false)}
                >
                    {({ processing }) => (
                        <>
                            {folio.settleCreditWarning && (
                                <Alert>
                                    <AlertCircleIcon />
                                    <AlertDescription>
                                        {folio.settleCreditWarning}
                                    </AlertDescription>
                                </Alert>
                            )}
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
