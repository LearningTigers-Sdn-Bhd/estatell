import { useForm } from '@inertiajs/react';
import { DateText } from '@/components/pms/date-text';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyInput } from '@/components/pms/money-input';
import { MoneyText } from '@/components/pms/money-text';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { useRetainedValue } from '@/components/pms/use-retained-value';
import { Field, FieldLabel } from '@/components/ui/field';
import { store } from '@/routes/finance/city-ledger/companies/payments/allocations';

type Payment = App.Data.CityLedger.ArPaymentRowData;
type OpenInvoice = App.Data.CityLedger.OpenArInvoiceData;

type FormProps = {
    open: boolean;
    onClose: () => void;
    companyId: number;
    payment: Payment;
    invoices: OpenInvoice[];
    currency: App.Data.Property.CurrencyData | null;
};

function AllocateForm({
    open,
    onClose,
    companyId,
    payment,
    invoices,
    currency,
}: FormProps) {
    const form = useForm<{ amounts: Record<number, string> }>({ amounts: {} });
    const { data, errors } = form;

    const submit = () => {
        form.transform((values) => ({
            allocations: Object.entries(values.amounts)
                .filter(([, amount]) => amount.trim() !== '')
                .map(([id, amount]) => ({
                    ar_invoice_id: Number(id),
                    amount,
                })),
        }));
        form.post(store.url({ company: companyId, payment: payment.id }), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={(next) => !next && onClose()}
            title="Allocate payment"
            description={
                <>
                    {payment.receiptNumber} ·{' '}
                    <MoneyText
                        amount={payment.unallocated}
                        currency={currency}
                    />{' '}
                    left to allocate.
                </>
            }
            errors={errors}
            onSubmit={submit}
            submitLabel="Allocate"
            processing={form.processing}
            submitDisabled={invoices.length === 0}
        >
            {invoices.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    The company has no invoice that still owes money.
                </p>
            ) : (
                invoices.map((invoice) => (
                    <Field key={invoice.id} orientation="responsive">
                        <FieldLabel htmlFor={`alloc-${invoice.id}`}>
                            <span className="flex flex-col">
                                <span>{invoice.number}</span>
                                <span className="text-xs font-normal text-muted-foreground">
                                    {invoice.dueOn && (
                                        <>
                                            Due{' '}
                                            <DateText date={invoice.dueOn} />
                                            ·{' '}
                                        </>
                                    )}
                                    Owes{' '}
                                    <MoneyText
                                        amount={invoice.balance}
                                        currency={currency}
                                    />
                                </span>
                            </span>
                        </FieldLabel>
                        <div className="w-44">
                            <MoneyInput
                                id={`alloc-${invoice.id}`}
                                value={data.amounts[invoice.id] ?? ''}
                                onChange={(value) =>
                                    form.setData('amounts', {
                                        ...data.amounts,
                                        [invoice.id]: value,
                                    })
                                }
                                currency={currency}
                            />
                        </div>
                    </Field>
                ))
            )}
        </FormOverlay>
    );
}

/**
 * Matches part of a payment to the company's open invoices, an amount for each.
 */
export function AllocateArPaymentDialog({
    payment,
    onClose,
    companyId,
    invoices,
    currency,
}: {
    payment: Payment | null;
    onClose: () => void;
    companyId: number;
    invoices: OpenInvoice[];
    currency: App.Data.Property.CurrencyData | null;
}) {
    const open = payment !== null;
    const kept = useRetainedValue(payment);
    const fresh = useFreshOnOpen(open);

    if (kept === null) {
        return null;
    }

    return (
        <AllocateForm
            key={`${kept.id}-${fresh}`}
            open={open}
            onClose={onClose}
            companyId={companyId}
            payment={kept}
            invoices={invoices}
            currency={currency}
        />
    );
}
