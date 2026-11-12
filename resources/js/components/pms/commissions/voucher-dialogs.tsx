import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { DatePicker } from '@/components/pms/date-picker';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyText } from '@/components/pms/money-text';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { today } from '@/components/pms/commissions/today';
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
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { approve, paid } from '@/routes/admin/commissions/vouchers';
import { store as storeAdjustment } from '@/routes/admin/commissions/vouchers/adjustments';

type Voucher = App.Data.Commissions.PayoutVoucherRowData;

type DialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    voucher: Voucher;
};

/** Confirms locking a Draft voucher. */
export function ApproveVoucherDialog({
    open,
    onOpenChange,
    voucher,
}: DialogProps) {
    const [processing, setProcessing] = useState(false);

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Approve {voucher.number}?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        The lines and the bank account are locked, and{' '}
                        {voucher.propertyName} can see the voucher. It can no
                        longer be adjusted.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <Button
                        disabled={processing}
                        onClick={() =>
                            router.put(
                                approve.url(voucher.id),
                                {},
                                {
                                    preserveScroll: true,
                                    onStart: () => setProcessing(true),
                                    onFinish: () => {
                                        setProcessing(false);
                                        onOpenChange(false);
                                    },
                                },
                            )
                        }
                    >
                        {processing && <Spinner />}
                        Approve
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

function MarkPaidForm({ open, onOpenChange, voucher }: DialogProps) {
    const form = useForm({ paid_on: today(), paid_reference: '' });
    const { data, errors } = form;

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title={`Mark ${voucher.number} as paid`}
            description={
                voucher.owedToEstatell
                    ? 'Record the transfer the property made to Estatell.'
                    : 'Record the transfer Estatell made to the property.'
            }
            errors={errors}
            onSubmit={() =>
                form.put(paid.url(voucher.id), {
                    preserveScroll: true,
                    onSuccess: () => onOpenChange(false),
                })
            }
            submitLabel="Mark as paid"
            processing={form.processing}
        >
            <Field data-invalid={!!errors.paid_on}>
                <FieldLabel htmlFor="paid_on">Transfer date</FieldLabel>
                <DatePicker
                    id="paid_on"
                    value={data.paid_on}
                    onChange={(value) => form.setData('paid_on', value)}
                    max={today()}
                    aria-invalid={!!errors.paid_on}
                />
                <FieldError>{errors.paid_on}</FieldError>
            </Field>
            <Field data-invalid={!!errors.paid_reference}>
                <FieldLabel htmlFor="paid_reference">Bank reference</FieldLabel>
                <Input
                    id="paid_reference"
                    value={data.paid_reference}
                    onChange={(event) =>
                        form.setData('paid_reference', event.target.value)
                    }
                    placeholder="e.g. MBB20261201-889"
                    maxLength={100}
                    autoComplete="off"
                    aria-invalid={!!errors.paid_reference}
                />
                <FieldError>{errors.paid_reference}</FieldError>
            </Field>
        </FormOverlay>
    );
}

/** Records the bank transfer of an Approved voucher. */
export function MarkPaidDialog(props: DialogProps) {
    return <MarkPaidForm key={useFreshOnOpen(props.open)} {...props} />;
}

function AdjustmentForm({ open, onOpenChange, voucher }: DialogProps) {
    const [key] = useState(() => crypto.randomUUID());
    const form = useForm({ amount: '', reason: '', idempotency_key: key });
    const { data, errors } = form;

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title="Add adjustment"
            description={
                <>
                    A positive amount is owed to the property, a negative one to
                    Estatell. The total is now{' '}
                    <MoneyText
                        amount={voucher.amount}
                        currency={voucher.currency}
                    />
                    .
                </>
            }
            errors={errors}
            onSubmit={() =>
                form.post(storeAdjustment.url(voucher.id), {
                    preserveScroll: true,
                    onSuccess: () => onOpenChange(false),
                })
            }
            submitLabel="Add adjustment"
            processing={form.processing}
        >
            <Field data-invalid={!!errors.amount}>
                <FieldLabel htmlFor="amount">Amount</FieldLabel>
                <Input
                    id="amount"
                    inputMode="decimal"
                    value={data.amount}
                    onChange={(event) =>
                        form.setData('amount', event.target.value)
                    }
                    placeholder="e.g. -25.00"
                    autoComplete="off"
                    aria-invalid={!!errors.amount}
                />
                <FieldDescription>
                    Use a minus sign for an amount the property owes.
                </FieldDescription>
                <FieldError>{errors.amount}</FieldError>
            </Field>
            <Field data-invalid={!!errors.reason}>
                <FieldLabel htmlFor="reason">Reason</FieldLabel>
                <Textarea
                    id="reason"
                    value={data.reason}
                    onChange={(event) =>
                        form.setData('reason', event.target.value)
                    }
                    placeholder="e.g. Goodwill credit for the booking engine outage"
                    maxLength={255}
                    rows={2}
                    aria-invalid={!!errors.reason}
                />
                <FieldError>{errors.reason}</FieldError>
            </Field>
        </FormOverlay>
    );
}

/** Adds a signed adjustment to a Draft voucher. */
export function AdjustmentDialog(props: DialogProps) {
    return <AdjustmentForm key={useFreshOnOpen(props.open)} {...props} />;
}
