import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import { MoneyText } from '@/components/pms/money-text';
import { AgentBillBreakdown } from '@/components/pms/payments/agent-bill-breakdown';
import { PaymentScheduleList } from '@/components/pms/payments/payment-schedule-list';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/portal/bookings/payment-proofs';

/**
 * Paying for a portal booking: what the agent and the guest each pay, the payment schedule, the proofs the agent sent with their status, and a form to send
 * a new proof (image or PDF, the amount and the bank reference). A proof is not a payment until the property accepts it.
 */
export function BookingPaymentSection({
    propertyCode,
    reservationId,
    schedule,
    bill,
    proofs,
    canUpload,
    currency,
}: {
    propertyCode: string;
    reservationId: number;
    schedule: App.Data.Reservations.PaymentScheduleData | null;
    bill: App.Data.Reservations.AgentBillData;
    proofs: App.Data.Payments.PaymentProofData[];
    canUpload: boolean;
    currency: App.Data.Property.CurrencyData | null;
}) {
    const [fileKey, setFileKey] = useState(0);
    const form = useForm<{
        file: File | null;
        amount: string;
        reference: string;
    }>({
        file: null,
        amount: '',
        reference: '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(
            store.url({ property: propertyCode, reservation: reservationId }),
            {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => {
                    form.reset();
                    setFileKey((key) => key + 1);
                },
            },
        );
    };

    return (
        <section className="flex max-w-3xl flex-col gap-4 rounded-lg border p-4">
            <h2 className="text-base font-medium">Payment</h2>
            <AgentBillBreakdown
                bill={bill}
                agentLabel="You pay"
                currency={currency}
            />
            {schedule !== null && (
                <PaymentScheduleList schedule={schedule} currency={currency} />
            )}

            {proofs.length > 0 && (
                <ul className="flex flex-col gap-2 text-sm">
                    {proofs.map((proof) => (
                        <li
                            key={proof.id}
                            className="flex flex-wrap items-center justify-between gap-2"
                        >
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
                                {proof.reference && (
                                    <span className="text-muted-foreground">
                                        · {proof.reference}
                                    </span>
                                )}
                            </a>
                            <span className="flex items-center gap-2">
                                {proof.rejectReason && (
                                    <span className="text-muted-foreground">
                                        {proof.rejectReason}
                                    </span>
                                )}
                                <StatusBadge status={proof.badge} />
                            </span>
                        </li>
                    ))}
                </ul>
            )}

            {canUpload && (
                <form
                    onSubmit={submit}
                    className="grid gap-3 border-t pt-3 sm:grid-cols-3"
                >
                    <Field data-invalid={!!form.errors.file}>
                        <FieldLabel htmlFor="proof-file">
                            Payment proof
                        </FieldLabel>
                        <Input
                            id="proof-file"
                            key={fileKey}
                            type="file"
                            accept="image/jpeg,image/png,image/webp,application/pdf"
                            onChange={(event) =>
                                form.setData(
                                    'file',
                                    event.target.files?.[0] ?? null,
                                )
                            }
                            aria-invalid={!!form.errors.file}
                        />
                        <FieldDescription>
                            Image or PDF, up to 10 MB.
                        </FieldDescription>
                        <FieldError>{form.errors.file}</FieldError>
                    </Field>
                    <Field data-invalid={!!form.errors.amount}>
                        <FieldLabel htmlFor="proof-amount">
                            Amount paid
                        </FieldLabel>
                        <Input
                            id="proof-amount"
                            inputMode="decimal"
                            value={form.data.amount}
                            onChange={(event) =>
                                form.setData('amount', event.target.value)
                            }
                            aria-invalid={!!form.errors.amount}
                        />
                        <FieldError>{form.errors.amount}</FieldError>
                    </Field>
                    <Field data-invalid={!!form.errors.reference}>
                        <FieldLabel htmlFor="proof-reference">
                            Bank reference
                        </FieldLabel>
                        <Input
                            id="proof-reference"
                            maxLength={100}
                            value={form.data.reference}
                            onChange={(event) =>
                                form.setData('reference', event.target.value)
                            }
                            aria-invalid={!!form.errors.reference}
                        />
                        <FieldError>{form.errors.reference}</FieldError>
                    </Field>
                    <div className="sm:col-span-3">
                        <Button
                            type="submit"
                            disabled={
                                form.processing || form.data.file === null
                            }
                            focusableWhenDisabled
                        >
                            {form.processing && (
                                <Spinner data-icon="inline-start" />
                            )}
                            Send proof
                        </Button>
                    </div>
                </form>
            )}
        </section>
    );
}
