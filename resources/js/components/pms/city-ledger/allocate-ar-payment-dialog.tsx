import { useForm } from '@inertiajs/react';
import { DateText } from '@/components/pms/date-text';
import { MoneyInput } from '@/components/pms/money-input';
import { MoneyText } from '@/components/pms/money-text';
import { FormError } from '@/components/pms/form-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/finance/city-ledger/companies/payments/allocations';

type Payment = App.Data.CityLedger.ArPaymentRowData;
type OpenInvoice = App.Data.CityLedger.OpenArInvoiceData;

function AllocateForm({
    companyId,
    payment,
    invoices,
    currency,
    onDone,
}: {
    companyId: number;
    payment: Payment;
    invoices: OpenInvoice[];
    currency: App.Enums.Currency | null;
    onDone: () => void;
}) {
    const form = useForm<{ amounts: Record<number, string> }>({ amounts: {} });
    const { data, errors } = form;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
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
            onSuccess: onDone,
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <FieldGroup>
                <FormError errors={errors} />
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
                                                <DateText
                                                    date={invoice.dueOn}
                                                />
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
            </FieldGroup>
            <DialogFooter>
                <DialogClose render={<Button variant="outline" />}>
                    Cancel
                </DialogClose>
                <Button
                    type="submit"
                    disabled={form.processing || invoices.length === 0}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    Allocate
                </Button>
            </DialogFooter>
        </form>
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
    currency: App.Enums.Currency | null;
}) {
    return (
        <Dialog
            open={payment !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Allocate payment</DialogTitle>
                    <DialogDescription>
                        {payment?.receiptNumber} ·{' '}
                        <MoneyText
                            amount={payment?.unallocated ?? '0.00'}
                            currency={currency}
                        />{' '}
                        left to allocate.
                    </DialogDescription>
                </DialogHeader>
                {payment && (
                    <AllocateForm
                        key={payment.id}
                        companyId={companyId}
                        payment={payment}
                        invoices={invoices}
                        currency={currency}
                        onDone={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}
