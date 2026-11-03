import { useForm } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import { useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyText } from '@/components/pms/money-text';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { accept, reject } from '@/routes/reservations/payment-proofs';

type Proof = App.Data.Payments.PaymentProofData;
type Option = { value: string; label: string };

function AcceptDialog({
    open,
    onOpenChange,
    reservationId,
    proof,
    methods,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    proof: Proof;
    methods: Option[];
}) {
    const form = useForm({
        payment_method_id: methods[0]?.value ?? '',
        amount: proof.amount,
    });

    return (
        <FormOverlay
            variant="dialog"
            size="sm"
            open={open}
            onOpenChange={onOpenChange}
            title="Accept payment proof"
            description="Records the payment on the booking, as an advance with the agent's reference."
            errors={form.errors}
            onSubmit={() =>
                form.post(
                    accept.url({
                        reservation: reservationId,
                        paymentProof: proof.id,
                    }),
                    {
                        preserveScroll: true,
                        onSuccess: () => onOpenChange(false),
                    },
                )
            }
            submitLabel="Record payment"
            processing={form.processing}
        >
            <Field data-invalid={!!form.errors.payment_method_id}>
                <FieldLabel htmlFor="proof-method">Method</FieldLabel>
                <EnumSelect
                    id="proof-method"
                    options={methods}
                    value={form.data.payment_method_id}
                    onValueChange={(value) =>
                        form.setData('payment_method_id', value)
                    }
                />
                <FieldError>{form.errors.payment_method_id}</FieldError>
            </Field>
            <Field data-invalid={!!form.errors.amount}>
                <FieldLabel htmlFor="proof-amount-received">
                    Amount received
                </FieldLabel>
                <Input
                    id="proof-amount-received"
                    inputMode="decimal"
                    value={form.data.amount}
                    onChange={(event) =>
                        form.setData('amount', event.target.value)
                    }
                    aria-invalid={!!form.errors.amount}
                />
                <FieldError>{form.errors.amount}</FieldError>
            </Field>
        </FormOverlay>
    );
}

function RejectDialog({
    open,
    onOpenChange,
    reservationId,
    proof,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    proof: Proof;
}) {
    const form = useForm({ reason: '' });

    return (
        <FormOverlay
            variant="dialog"
            size="sm"
            open={open}
            onOpenChange={onOpenChange}
            title="Reject payment proof"
            description="The agent sees your reason in the portal."
            errors={form.errors}
            onSubmit={() =>
                form.post(
                    reject.url({
                        reservation: reservationId,
                        paymentProof: proof.id,
                    }),
                    {
                        preserveScroll: true,
                        onSuccess: () => onOpenChange(false),
                    },
                )
            }
            submitLabel="Reject"
            submitVariant="destructive"
            processing={form.processing}
            submitDisabled={form.data.reason.trim() === ''}
        >
            <Field data-invalid={!!form.errors.reason}>
                <FieldLabel htmlFor="proof-reject-reason">Reason</FieldLabel>
                <Textarea
                    id="proof-reject-reason"
                    rows={2}
                    maxLength={500}
                    placeholder="e.g. The amount is not in our account yet."
                    value={form.data.reason}
                    onChange={(event) =>
                        form.setData('reason', event.target.value)
                    }
                />
                <FieldError>{form.errors.reason}</FieldError>
            </Field>
        </FormOverlay>
    );
}

/**
 * The payment proofs an agent sent for the reservation, on the summary card: open the file, then Accept (records the
 * payment) or Reject. Accept and Reject show only with payment.record (`methods` is empty without it).
 */
export function PaymentProofReview({
    reservationId,
    proofs,
    methods,
    currency,
}: {
    reservationId: number;
    proofs: Proof[];
    methods: Option[];
    currency: App.Data.Property.CurrencyData | null;
}) {
    const [open, setOpen] = useState<{
        id: number;
        action: 'accept' | 'reject';
    } | null>(null);

    return (
        <ul className="flex flex-col gap-2 text-sm">
            {proofs.map((proof) => (
                <li key={proof.id} className="flex flex-col gap-1">
                    <span className="flex items-center justify-between gap-2">
                        <a
                            href={proof.fileUrl}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1.5 underline-offset-4 hover:underline"
                        >
                            <FileText className="size-4" aria-hidden />
                            <MoneyText
                                amount={proof.amount}
                                currency={currency}
                            />
                        </a>
                        <StatusBadge status={proof.badge} />
                    </span>
                    <span className="text-xs text-muted-foreground">
                        {proof.uploadedBy}
                        {proof.reference ? ` · ${proof.reference}` : ''}
                        {proof.rejectReason ? ` · ${proof.rejectReason}` : ''}
                    </span>
                    {proof.status === 'submitted' && methods.length > 0 && (
                        <span className="flex gap-2">
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    setOpen({ id: proof.id, action: 'accept' })
                                }
                            >
                                Accept
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() =>
                                    setOpen({ id: proof.id, action: 'reject' })
                                }
                            >
                                Reject
                            </Button>
                        </span>
                    )}
                    {open?.id === proof.id && open.action === 'accept' && (
                        <AcceptDialog
                            open
                            onOpenChange={(next) => !next && setOpen(null)}
                            reservationId={reservationId}
                            proof={proof}
                            methods={methods}
                        />
                    )}
                    {open?.id === proof.id && open.action === 'reject' && (
                        <RejectDialog
                            open
                            onOpenChange={(next) => !next && setOpen(null)}
                            reservationId={reservationId}
                            proof={proof}
                        />
                    )}
                </li>
            ))}
        </ul>
    );
}
