import { useForm } from '@inertiajs/react';
import { DateText } from '@/components/pms/date-text';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyText } from '@/components/pms/money-text';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Button } from '@/components/ui/button';
import { DialogClose } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { store } from '@/routes/finance/city-ledger/invoices/credit-notes';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    invoice: App.Data.CityLedger.ArInvoiceData;
    currency: App.Enums.Currency | null;
    /** Called with the payment the user wants to reverse; the page swaps this Dialog for the Reverse one. */
    onReverse: (allocation: App.Data.CityLedger.ArAllocationRowData) => void;
};

function BlockedRows({
    invoice,
    currency,
    onReverse,
}: Pick<Props, 'invoice' | 'currency' | 'onReverse'>) {
    const company = invoice.row.companyName;
    const canReverseAny = invoice.creditBlockedBy.some((a) => a.canReverse);

    return (
        <div className="flex flex-col gap-3 text-sm">
            <p>
                This invoice is paid. A credit note can only reduce what is
                still owed (<MoneyText amount="0.00" currency={currency} />
                ). To credit it, reverse a payment first:
            </p>
            <ul className="flex flex-col divide-y rounded-lg border">
                {invoice.creditBlockedBy.map((allocation) => (
                    <li
                        key={allocation.id}
                        className="flex items-center justify-between gap-3 p-2"
                    >
                        <span>
                            <span className="font-medium">
                                {allocation.receiptNumber}
                            </span>{' '}
                            <DateText date={allocation.receivedOn} />{' '}
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
                                onClick={() => onReverse(allocation)}
                            >
                                Reverse
                            </Button>
                        )}
                    </li>
                ))}
            </ul>
            <p className="text-muted-foreground">
                The money stays with {company} as credit. Allocate it again
                after the credit note.
            </p>
            {!canReverseAny && (
                <p className="text-muted-foreground">
                    Ask a user who can reverse payments.
                </p>
            )}
        </div>
    );
}

function CreditNoteForm({
    open,
    onOpenChange,
    invoice,
    currency,
    onReverse,
}: Props) {
    const form = useForm({ amount: invoice.row.balance ?? '', reason: '' });
    const { data, errors } = form;

    const submit = () => {
        form.post(store.url({ invoice: invoice.row.id }), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    const hasBalance = Number(invoice.row.balance ?? 0) > 0;
    const blocked = invoice.creditBlockedBy;

    if (!hasBalance) {
        return (
            <FormOverlay
                variant="dialog"
                open={open}
                onOpenChange={onOpenChange}
                title="Issue credit note"
                description={`Corrects invoice ${invoice.row.number}.`}
                errors={errors}
                onSubmit={() => onOpenChange(false)}
                submitLabel="Close"
                processing={false}
                footer={
                    <DialogClose render={<Button variant="outline" />}>
                        Close
                    </DialogClose>
                }
            >
                {blocked.length > 0 ? (
                    <BlockedRows
                        invoice={invoice}
                        currency={currency}
                        onReverse={onReverse}
                    />
                ) : (
                    <p className="text-sm">
                        This invoice has no balance left, so there is nothing to
                        credit.
                    </p>
                )}
            </FormOverlay>
        );
    }

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title="Issue credit note"
            description={`Corrects invoice ${invoice.row.number}.`}
            errors={errors}
            onSubmit={submit}
            submitLabel="Issue credit note"
            processing={form.processing}
        >
            <Field data-invalid={!!errors.amount}>
                <FieldLabel htmlFor="credit-amount">Amount</FieldLabel>
                <Input
                    id="credit-amount"
                    inputMode="decimal"
                    value={data.amount}
                    onChange={(event) =>
                        form.setData('amount', event.target.value)
                    }
                    autoComplete="off"
                    autoFocus
                    aria-invalid={!!errors.amount}
                />
                <FieldDescription>
                    At most{' '}
                    <MoneyText
                        amount={invoice.row.balance ?? '0.00'}
                        currency={currency}
                    />
                    .
                    {blocked.length > 0 && (
                        <>
                            {' '}
                            To credit more, reverse a payment first:{' '}
                            {blocked.map((allocation, position) => (
                                <span key={allocation.id}>
                                    {position > 0 && ', '}
                                    {allocation.receiptNumber} (
                                    <MoneyText
                                        amount={allocation.amount}
                                        currency={currency}
                                    />
                                    )
                                </span>
                            ))}
                            .
                        </>
                    )}
                </FieldDescription>
                <FieldError>{errors.amount}</FieldError>
            </Field>
            <Field data-invalid={!!errors.reason}>
                <FieldLabel htmlFor="credit-reason">Reason</FieldLabel>
                <Textarea
                    id="credit-reason"
                    value={data.reason}
                    onChange={(event) =>
                        form.setData('reason', event.target.value)
                    }
                    maxLength={500}
                    rows={3}
                    aria-invalid={!!errors.reason}
                />
                <FieldError>{errors.reason}</FieldError>
            </Field>
        </FormOverlay>
    );
}

/**
 * Issues a credit note against an invoice. The note is applied to the invoice at once.
 */
export function IssueCreditNoteDialog(props: Props) {
    return <CreditNoteForm key={useFreshOnOpen(props.open)} {...props} />;
}
