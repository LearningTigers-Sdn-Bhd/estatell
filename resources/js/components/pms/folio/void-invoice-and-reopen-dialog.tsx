import { Form, router } from '@inertiajs/react';
import { useState } from 'react';
import { DateText } from '@/components/pms/date-text';
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
import { voidMethod as voidArDocument } from '@/routes/finance/city-ledger/invoices';
import { voidInvoiceAndReopen } from '@/routes/reservations/folios';

type Allocation = App.Data.CityLedger.ArAllocationRowData;
type CreditNote = App.Data.CityLedger.ArInvoiceRowData;

/**
 * One credit note that blocks the void. **Void** asks for a confirmation in the row, posts to the AR void route and
 * reloads the invoice, so the Dialog shows what is left.
 */
function CreditNoteRow({
    note,
    canVoid,
    currency,
}: {
    note: CreditNote;
    canVoid: boolean;
    currency: App.Data.Property.CurrencyData | null;
}) {
    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);

    const submit = () => {
        setProcessing(true);
        router.post(
            voidArDocument.url({ invoice: note.id }),
            {},
            {
                preserveScroll: true,
                except: KEEP_OPTIONS,
                onSuccess: () =>
                    router.reload({
                        only: ['creditInvoice'],
                        onFinish: () => setProcessing(false),
                    }),
                onError: () => setProcessing(false),
            },
        );
    };

    return (
        <li className="flex flex-col gap-2 p-2">
            <div className="flex items-center justify-between gap-3">
                <span>
                    Credit note{' '}
                    <span className="font-medium">{note.number}</span>{' '}
                    <MoneyText amount={note.amount} currency={currency} />
                </span>
                {canVoid && !confirming && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => setConfirming(true)}
                    >
                        Void
                    </Button>
                )}
            </div>
            {confirming && (
                <div className="flex items-center justify-end gap-2">
                    <span className="text-muted-foreground">
                        The invoice owes the credit again.
                    </span>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={processing}
                        onClick={() => setConfirming(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        size="sm"
                        disabled={processing}
                        focusableWhenDisabled
                        onClick={submit}
                    >
                        {processing && <Spinner data-icon="inline-start" />}
                        Void {note.number}
                    </Button>
                </div>
            )}
        </li>
    );
}

/**
 * Voids the folio's AR invoice and reopens the folio after a confirmation; the City ledger line is reversed in the
 * same step. While credit notes or payments block the void, it lists them with **Void** and **Reverse** instead of
 * the confirm button. **Reverse** hands over to the Reverse allocation Dialog, which returns here. A refusal rolls
 * everything back and is an error toast. See docs/13-folio/01-folios-and-lines.md#close-and-reopen.
 */
export function VoidInvoiceAndReopenDialog({
    reservationId,
    folioId,
    invoiceNumber,
    invoice,
    currency,
    open,
    onOpenChange,
    onReverse,
}: {
    reservationId: number;
    folioId: number;
    invoiceNumber: string;
    invoice: App.Data.CityLedger.ArInvoiceData | null | undefined;
    currency: App.Data.Property.CurrencyData | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Called with the payment to reverse; the page swaps this Dialog for the Reverse one. */
    onReverse: (allocation: Allocation) => void;
}) {
    const notes = (invoice?.creditNotes ?? []).filter(
        (note) => note.status.value !== 'void',
    );
    const allocations = invoice?.creditBlockedBy ?? [];
    const blocked = notes.length > 0 || allocations.length > 0;
    const canVoidNotes = invoice?.canVoidCreditNotes ?? false;
    const missingNoteRight = notes.length > 0 && !canVoidNotes;
    const missingReverseRight = allocations.some((a) => !a.canReverse);

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Void invoice {invoiceNumber} and reopen this folio?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        {blocked
                            ? 'Clear these first:'
                            : 'The City ledger line is reversed, so the folio owes its charges again. Settle again to issue a new invoice.'}
                    </AlertDialogDescription>
                </AlertDialogHeader>
                {blocked && (
                    <div className="flex flex-col gap-3 text-sm">
                        <ul className="flex flex-col divide-y rounded-lg border">
                            {notes.map((note) => (
                                <CreditNoteRow
                                    key={note.id}
                                    note={note}
                                    canVoid={canVoidNotes}
                                    currency={currency}
                                />
                            ))}
                            {allocations.map((allocation) => (
                                <li
                                    key={allocation.id}
                                    className="flex items-center justify-between gap-3 p-2"
                                >
                                    <span>
                                        Payment{' '}
                                        <span className="font-medium">
                                            {allocation.receiptNumber}
                                        </span>{' '}
                                        <DateText
                                            date={allocation.receivedOn}
                                        />{' '}
                                        <MoneyText
                                            amount={allocation.amount}
                                            currency={currency}
                                        />
                                    </span>
                                    {allocation.canReverse && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                onReverse(allocation)
                                            }
                                        >
                                            Reverse
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                        {allocations.length > 0 && invoice && (
                            <p className="text-muted-foreground">
                                The money stays with {invoice.row.companyName}{' '}
                                as credit. Allocate it again after you settle.
                            </p>
                        )}
                        {missingNoteRight && (
                            <p className="text-muted-foreground">
                                Ask a user who can void credit notes.
                            </p>
                        )}
                        {missingReverseRight && (
                            <p className="text-muted-foreground">
                                Ask a user who can reverse payments.
                            </p>
                        )}
                    </div>
                )}
                {blocked ? (
                    <AlertDialogFooter>
                        <AlertDialogCancel>Close</AlertDialogCancel>
                    </AlertDialogFooter>
                ) : (
                    <Form
                        {...voidInvoiceAndReopen.form({
                            reservation: reservationId,
                            folio: folioId,
                        })}
                        options={{
                            preserveScroll: true,
                            except: KEEP_OPTIONS,
                        }}
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
                )}
            </AlertDialogContent>
        </AlertDialog>
    );
}
