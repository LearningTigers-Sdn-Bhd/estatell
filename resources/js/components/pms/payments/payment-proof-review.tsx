import { useForm } from '@inertiajs/react';
import { Check, Eye, X } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyText } from '@/components/pms/money-text';
import { StatusBadge } from '@/components/pms/status-badge';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button, buttonVariants } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import * as companySlips from '@/routes/finance/city-ledger/companies/payment-slips';
import { accept, reject } from '@/routes/reservations/payment-proofs';

type Proof = App.Data.Payments.PaymentProofData;
type Option = { value: string; label: string };
/** A booking's proof or a company's slip: where Accept and Reject post, and what the Dialog says. */
type Target = { reservationId: number } | { companyId: number };

function actionUrl(
    target: Target,
    proofId: number,
    action: 'accept' | 'reject',
): string {
    if ('companyId' in target) {
        const args = { company: target.companyId, paymentProof: proofId };

        return action === 'accept'
            ? companySlips.accept.url(args)
            : companySlips.reject.url(args);
    }

    const args = { reservation: target.reservationId, paymentProof: proofId };

    return action === 'accept' ? accept.url(args) : reject.url(args);
}

function AcceptDialog({
    open,
    onOpenChange,
    target,
    proof,
    methods,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    target: Target;
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
            description={
                'companyId' in target
                    ? proof.invoiceNumber
                        ? `Records a city ledger payment with the company's reference. It fills invoice ${proof.invoiceNumber} first, then the oldest open invoices; the rest stays as credit.`
                        : "Records a city ledger payment with the company's reference. It fills the oldest open invoices first; the rest stays as credit."
                    : "Records the payment on the booking, as an advance with the agent's reference."
            }
            errors={form.errors}
            onSubmit={() =>
                form.post(actionUrl(target, proof.id, 'accept'), {
                    preserveScroll: true,
                    onSuccess: () => onOpenChange(false),
                })
            }
            submitLabel="Record payment"
            processing={form.processing}
        >
            <Field data-invalid={!!form.errors.payment_method_id}>
                <FieldLabel htmlFor="proof-method">Method</FieldLabel>
                <EnumSelect
                    id="proof-method"
                    options={methods}
                    disabled={methods.length === 1}
                    value={form.data.payment_method_id}
                    onValueChange={(value) =>
                        form.setData('payment_method_id', value)
                    }
                />
                {methods.length === 1 && (
                    <FieldDescription>
                        A payment proof is a bank transfer.
                    </FieldDescription>
                )}
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
    target,
    proof,
    currency,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    target: Target;
    proof: Proof;
    currency: App.Data.Property.CurrencyData | null;
}) {
    const form = useForm({ reason: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(actionUrl(target, proof.id, 'reject'), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Reject the proof of{' '}
                        <MoneyText amount={proof.amount} currency={currency} />?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        Nothing is recorded. {proof.uploadedBy} sees your reason
                        in the portal and can send a new slip.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <Field data-invalid={!!form.errors.reason}>
                        <FieldLabel htmlFor="proof-reject-reason">
                            Reason
                        </FieldLabel>
                        <Textarea
                            id="proof-reject-reason"
                            rows={2}
                            maxLength={500}
                            placeholder="e.g. The amount is not in our account yet."
                            value={form.data.reason}
                            onChange={(event) =>
                                form.setData('reason', event.target.value)
                            }
                            aria-invalid={!!form.errors.reason}
                        />
                        <FieldError>{form.errors.reason}</FieldError>
                    </Field>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={
                                form.processing ||
                                form.data.reason.trim() === ''
                            }
                            focusableWhenDisabled
                        >
                            {form.processing && (
                                <Spinner data-icon="inline-start" />
                            )}
                            Reject proof
                        </Button>
                    </AlertDialogFooter>
                </form>
            </AlertDialogContent>
        </AlertDialog>
    );
}

/** When the proof was sent, as "11 Nov 2026" in the viewer's time zone. */
function sentOn(timestamp: string): string {
    return new Date(timestamp).toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

/**
 * The payment proofs an agent sent, as a table: the date, the amount (with the invoice a slip is for), who sent it,
 * the bank reference, Paid into and the status, with the reason under a rejected one. Every row has View slip; a proof
 * not checked yet has Accept (records the payment) and Reject. Accept and Reject show only with the payment permission
 * (`methods` is empty without it). The reservation's Folio tab and the company's Payments tab show it.
 */
export function PaymentProofReview({
    reservationId,
    companyId,
    proofs,
    methods,
    currency,
    emptyText = 'No payment proofs yet',
}: {
    reservationId?: number;
    companyId?: number;
    emptyText?: string;
    proofs: Proof[];
    methods: Option[];
    currency: App.Data.Property.CurrencyData | null;
}) {
    const target: Target =
        companyId !== undefined
            ? { companyId }
            : { reservationId: reservationId as number };
    const [open, setOpen] = useState<{
        id: number;
        action: 'accept' | 'reject';
    } | null>(null);
    const openProof = proofs.find((proof) => proof.id === open?.id) ?? null;

    return (
        <>
            <div className="overflow-hidden rounded-xl border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Sent on</TableHead>
                            <TableHead className="text-right">Amount</TableHead>
                            <TableHead>Sent by</TableHead>
                            <TableHead>Bank ref</TableHead>
                            <TableHead>Paid into</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>
                                <span className="sr-only">Actions</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {proofs.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={7}>
                                    <EmptyState title={emptyText} />
                                </TableCell>
                            </TableRow>
                        )}
                        {proofs.map((proof) => (
                            <TableRow key={proof.id} className="align-top">
                                <TableCell>
                                    <time
                                        dateTime={proof.uploadedAt}
                                        className="tabular-nums"
                                    >
                                        {sentOn(proof.uploadedAt)}
                                    </time>
                                </TableCell>
                                <TableCell className="text-right">
                                    <span className="flex flex-col items-end">
                                        <span className="font-medium">
                                            <MoneyText
                                                amount={proof.amount}
                                                currency={currency}
                                            />
                                        </span>
                                        {proof.invoiceNumber && (
                                            <span className="text-xs text-muted-foreground">
                                                For {proof.invoiceNumber}
                                            </span>
                                        )}
                                    </span>
                                </TableCell>
                                <TableCell>{proof.uploadedBy}</TableCell>
                                <TableCell>{proof.reference ?? '—'}</TableCell>
                                <TableCell>{proof.paidInto ?? '—'}</TableCell>
                                <TableCell>
                                    <span className="flex flex-col gap-1">
                                        <StatusBadge status={proof.badge} />
                                        {proof.rejectReason && (
                                            <span className="max-w-60 text-xs whitespace-normal text-muted-foreground">
                                                {proof.rejectReason}
                                            </span>
                                        )}
                                    </span>
                                </TableCell>
                                <TableCell>
                                    <span className="flex justify-end">
                                        <ButtonGroup>
                                            <a
                                                data-slot="button"
                                                href={proof.fileUrl}
                                                target="_blank"
                                                rel="noreferrer"
                                                className={buttonVariants({
                                                    variant: 'outline',
                                                    size: 'sm',
                                                })}
                                            >
                                                <Eye data-icon="inline-start" />
                                                View slip
                                            </a>
                                            {proof.status === 'submitted' &&
                                                methods.length > 0 && (
                                                    <>
                                                        <Button
                                                            size="sm"
                                                            onClick={() =>
                                                                setOpen({
                                                                    id: proof.id,
                                                                    action: 'accept',
                                                                })
                                                            }
                                                        >
                                                            <Check data-icon="inline-start" />
                                                            Accept
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            variant="destructive"
                                                            onClick={() =>
                                                                setOpen({
                                                                    id: proof.id,
                                                                    action: 'reject',
                                                                })
                                                            }
                                                        >
                                                            <X data-icon="inline-start" />
                                                            Reject
                                                        </Button>
                                                    </>
                                                )}
                                        </ButtonGroup>
                                    </span>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
            {openProof !== null && open?.action === 'accept' && (
                <AcceptDialog
                    open
                    onOpenChange={(next) => !next && setOpen(null)}
                    target={target}
                    proof={openProof}
                    methods={methods}
                />
            )}
            {openProof !== null && open?.action === 'reject' && (
                <RejectDialog
                    open
                    onOpenChange={(next) => !next && setOpen(null)}
                    target={target}
                    proof={openProof}
                    currency={currency}
                />
            )}
        </>
    );
}
