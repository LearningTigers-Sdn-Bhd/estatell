import { Form, Head, Link, useForm } from '@inertiajs/react';
import {
    ArrowUpRight,
    Ban,
    CircleAlert,
    ChevronDown,
    CircleCheck,
    Clock,
    Copy,
    FilePlus2,
    Mail,
    RefreshCw,
    Wrench,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { DateText } from '@/components/pms/date-text';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { toast } from '@/components/ui/toast';
import { useClipboard } from '@/hooks/use-clipboard';
import { show as showArInvoice } from '@/routes/finance/city-ledger/invoices';
import {
    cancel,
    consolidate,
    creditNote,
    email as emailRoute,
    index,
    refresh,
    show,
} from '@/routes/finance/e-invoices';
import { folio as folioTab } from '@/routes/reservations';
import { edit as eInvoiceSettings } from '@/routes/settings/e-invoice';
import { edit as emailSettings } from '@/routes/settings/emails';

type Badge = { value: string; label: string; tone: App.Enums.Tone };

type Submission = {
    id: number;
    number: string;
    kind: 'invoice' | 'credit_note' | 'summary';
    status: Badge;
    amounts: {
        subtotal: string | null;
        tax: string | null;
        total: string | null;
    };
    period: { from: string; to: string } | null;
    buyer: {
        name: string;
        tax_number: string | null;
        id_label: string | null;
        id_number: string | null;
        email: string | null;
        phone: string | null;
        address: string | null;
    } | null;
    stays_included: number | null;
    reservation: {
        id: number;
        reference: string;
        folio_id: number;
        folio_number: string;
    } | null;
    ar_invoice: { id: number; number: string; company: string | null } | null;
    original: { id: number; number: string } | null;
    credited: string | null;
    credit_notes: {
        id: number;
        number: string;
        amount: string | null;
        status: Badge;
        issued_at: string | null;
    }[];
    accepted_at: string | null;
    cancelled_at: string | null;
    cancel_reason: string | null;
    cancel_until: string | null;
    can_cancel: boolean;
    can_credit_note: boolean;
    can_check_status: boolean;
    guest_link: string | null;
    guest_email: {
        to: string | null;
        subject: string;
        paragraphs: string[];
        details: Record<string, string>;
    } | null;
    lhdn_reference: string | null;
    reasons: { message: string; fix: string }[];
    history: { label: string; at: string | null }[];
};

type Props = {
    submission: Submission;
    currency: App.Data.Property.CurrencyData | null;
    canEditEmail: boolean;
};

const VISIBLE_CREDIT_NOTES = 3;

const KIND_LABEL: Record<Submission['kind'], string> = {
    invoice: 'E-invoice',
    credit_note: 'Credit note',
    summary: 'Monthly summary',
};

function Figure({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-1 rounded-lg border p-3">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-sm font-medium">{children}</dd>
        </div>
    );
}

function Detail({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid gap-0.5 px-3 py-2 sm:grid-cols-3 sm:gap-4">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="min-w-0 text-sm break-words sm:col-span-2">
                {children}
            </dd>
        </div>
    );
}

function CreditNoteList({
    submission,
    money,
}: {
    submission: Submission;
    money: (amount: string | null) => ReactNode;
}) {
    const [showAll, setShowAll] = useState(false);
    const notes = showAll
        ? submission.credit_notes
        : submission.credit_notes.slice(0, VISIBLE_CREDIT_NOTES);
    const hidden = submission.credit_notes.length - VISIBLE_CREDIT_NOTES;

    return (
        <div className="flex flex-col divide-y rounded-lg border">
            {submission.credited !== null && (
                <p className="px-3 py-2 text-sm">
                    Credited <strong>{money(submission.credited)}</strong> of{' '}
                    {money(submission.amounts.total)}
                </p>
            )}
            <ul className="flex flex-col divide-y">
                {notes.map((note) => (
                    <li
                        key={note.id}
                        className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 text-sm"
                    >
                        <Link
                            href={show.url({ submission: note.id })}
                            className="font-medium underline-offset-2 hover:underline"
                        >
                            {note.number}
                        </Link>
                        <span className="text-muted-foreground tabular-nums">
                            {note.issued_at}
                        </span>
                        <span className="ms-auto flex items-center gap-3">
                            {money(note.amount)}
                            <StatusBadge status={note.status} />
                        </span>
                    </li>
                ))}
            </ul>
            {hidden > 0 && (
                <Button
                    variant="ghost"
                    size="sm"
                    className="rounded-t-none"
                    onClick={() => setShowAll(!showAll)}
                >
                    {showAll
                        ? 'Show fewer'
                        : `Show all ${submission.credit_notes.length}`}
                </Button>
            )}
        </div>
    );
}

/** Where staff correct the details LHDN rejected. */
function fixUrl(submission: Submission): string | null {
    if (submission.reservation) {
        return folioTab.url(
            { reservation: submission.reservation.id },
            { query: { folio: String(submission.reservation.folio_id) } },
        );
    }

    if (submission.ar_invoice) {
        return showArInvoice.url({ invoice: submission.ar_invoice.id });
    }

    return null;
}

/**
 * The one thing a manager needs to know about this document, and what to do next. Dates, amounts and button names
 * are bold so the eye lands on them.
 */
function StatusNotice({
    submission,
    currency,
}: {
    submission: Submission;
    currency: App.Data.Property.CurrencyData | null;
}) {
    const { status } = submission;

    if (status.value === 'valid') {
        return (
            <Alert>
                <CircleCheck className="text-success" />
                <AlertTitle>
                    Accepted by LHDN
                    {submission.accepted_at && ` on ${submission.accepted_at}`}
                </AlertTitle>
                <AlertDescription>
                    <p>
                        {submission.kind === 'summary' && (
                            <>
                                It files{' '}
                                <strong>
                                    {submission.stays_included} stays
                                </strong>{' '}
                                whose guests did not ask for their own
                                e-invoice. Nothing more to do.
                            </>
                        )}
                        {submission.kind === 'credit_note' && (
                            <>
                                It reduces e-invoice{' '}
                                <strong>{submission.original?.number}</strong>{' '}
                                by{' '}
                                <strong>
                                    {submission.amounts.total && (
                                        <MoneyText
                                            amount={submission.amounts.total}
                                            currency={currency}
                                        />
                                    )}
                                </strong>
                                . Nothing more to do.
                            </>
                        )}
                        {submission.kind === 'invoice' && (
                            <>
                                It is filed. Use <strong>Email to guest</strong>{' '}
                                to send the guest their copy.
                            </>
                        )}
                    </p>
                    {submission.cancel_until && (
                        <p>
                            You can cancel it until{' '}
                            <strong>{submission.cancel_until}</strong>. After
                            that, correct it with a credit note.
                        </p>
                    )}
                </AlertDescription>
            </Alert>
        );
    }

    if (status.value === 'invalid') {
        return (
            <Alert variant="destructive">
                <CircleAlert />
                <AlertTitle>Rejected by LHDN. Nothing was filed.</AlertTitle>
                <AlertDescription>
                    <ul className="flex flex-col gap-2">
                        {submission.reasons.map((reason) => (
                            <li
                                key={reason.message}
                                className="flex flex-col gap-0.5"
                            >
                                <strong>{reason.message}</strong>
                                <span>{reason.fix}</span>
                            </li>
                        ))}
                        {submission.reasons.length === 0 && (
                            <li>Check the buyer details, then submit again.</li>
                        )}
                    </ul>
                </AlertDescription>
            </Alert>
        );
    }

    if (status.value === 'cancelled') {
        return (
            <Alert>
                <Ban className="text-muted-foreground" />
                <AlertTitle>
                    Cancelled
                    {submission.cancelled_at &&
                        ` on ${submission.cancelled_at}`}
                </AlertTitle>
                <AlertDescription>
                    <p>
                        LHDN no longer counts it, and the guest link no longer
                        works.
                        {submission.cancel_reason && (
                            <>
                                {' '}
                                Reason:{' '}
                                <strong>{submission.cancel_reason}</strong>.
                            </>
                        )}
                    </p>
                    {submission.reservation && (
                        <p>
                            To bill the guest again, issue a new e-invoice from
                            the folio.
                        </p>
                    )}
                </AlertDescription>
            </Alert>
        );
    }

    return (
        <Alert>
            <Clock className="text-info" />
            <AlertTitle>LHDN is checking it</AlertTitle>
            <AlertDescription>
                This usually takes under a minute. Press{' '}
                <strong>Check status</strong> to see the result.
            </AlertDescription>
        </Alert>
    );
}

function CancelAction({ submission }: { submission: Submission }) {
    const [open, setOpen] = useState(false);

    return (
        <AlertDialog open={open} onOpenChange={setOpen}>
            <AlertDialogTrigger render={<Button variant="outline" />}>
                <Ban data-icon="inline-start" />
                Cancel
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Cancel {submission.number}?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        LHDN will no longer count it, and the guest link stops
                        working. This cannot be undone.
                        {submission.reservation &&
                            ' You can issue a new e-invoice from the folio afterwards.'}
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <Form
                    {...cancel.form({ submission: submission.id })}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <Field data-invalid={!!errors.reason}>
                                <FieldLabel htmlFor="cancel-reason">
                                    Reason
                                </FieldLabel>
                                <Input
                                    id="cancel-reason"
                                    name="reason"
                                    required
                                    maxLength={500}
                                    placeholder="For example: wrong buyer name"
                                    aria-invalid={!!errors.reason}
                                />
                                <FieldDescription>
                                    LHDN keeps this reason with the e-invoice.
                                </FieldDescription>
                                <FieldError>{errors.reason}</FieldError>
                            </Field>
                            <AlertDialogFooter>
                                <AlertDialogCancel>Keep it</AlertDialogCancel>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                    focusableWhenDisabled
                                >
                                    {processing && (
                                        <Spinner data-icon="inline-start" />
                                    )}
                                    Cancel e-invoice
                                </Button>
                            </AlertDialogFooter>
                        </>
                    )}
                </Form>
            </AlertDialogContent>
        </AlertDialog>
    );
}

function CreditNoteForm({
    open,
    onOpenChange,
    submission,
    currency,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    submission: Submission;
    currency: App.Data.Property.CurrencyData | null;
}) {
    const form = useForm({
        amount: '',
        reason: '',
        credit_note_number: `CN-${submission.number}`,
    });
    const { data, errors } = form;

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title="Issue credit note"
            description={`Use this when the guest should pay less than ${submission.number}, for example a refund after checkout. The e-invoice stays filed and LHDN records the credit note against it.`}
            errors={errors}
            onSubmit={() =>
                form.post(creditNote.url({ submission: submission.id }), {
                    preserveScroll: true,
                    onSuccess: () => onOpenChange(false),
                })
            }
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
                {submission.amounts.total && (
                    <FieldDescription>
                        The e-invoice is for{' '}
                        <MoneyText
                            amount={submission.amounts.total}
                            currency={currency}
                        />
                        .
                    </FieldDescription>
                )}
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
                    rows={2}
                    placeholder="For example: late checkout fee waived"
                    aria-invalid={!!errors.reason}
                />
                <FieldError>{errors.reason}</FieldError>
            </Field>
            <Field data-invalid={!!errors.credit_note_number}>
                <FieldLabel htmlFor="credit-number">
                    Credit note number
                </FieldLabel>
                <Input
                    id="credit-number"
                    value={data.credit_note_number}
                    onChange={(event) =>
                        form.setData('credit_note_number', event.target.value)
                    }
                    maxLength={50}
                    aria-invalid={!!errors.credit_note_number}
                />
                <FieldError>{errors.credit_note_number}</FieldError>
            </Field>
        </FormOverlay>
    );
}

function CreditNoteDialog(props: Parameters<typeof CreditNoteForm>[0]) {
    return <CreditNoteForm key={useFreshOnOpen(props.open)} {...props} />;
}

function EmailGuestForm({
    open,
    onOpenChange,
    submission,
    canEditEmail,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    submission: Submission;
    canEditEmail: boolean;
}) {
    const email = submission.guest_email!;
    const form = useForm({ to: email.to ?? '' });

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title="Email e-invoice to guest"
            description="The guest gets this email with a View e-invoice button that opens their copy on MyInvois. Replies come to the property's contact email."
            errors={form.errors}
            onSubmit={() =>
                form.post(emailRoute.url({ submission: submission.id }), {
                    preserveScroll: true,
                    onSuccess: () => onOpenChange(false),
                })
            }
            submitLabel="Send email"
            processing={form.processing}
        >
            <Field data-invalid={!!form.errors.to}>
                <FieldLabel htmlFor="guest-email-to">To</FieldLabel>
                <Input
                    id="guest-email-to"
                    type="email"
                    value={form.data.to}
                    onChange={(event) => form.setData('to', event.target.value)}
                    autoComplete="off"
                    autoFocus={!email.to}
                    aria-invalid={!!form.errors.to}
                />
                {!email.to && (
                    <FieldDescription>
                        The e-invoice has no email address. Ask the guest for
                        one.
                    </FieldDescription>
                )}
                <FieldError>{form.errors.to}</FieldError>
            </Field>
            <div className="flex flex-col gap-3 rounded-lg border bg-muted/40 p-3 text-sm">
                <p className="font-medium">{email.subject}</p>
                {email.paragraphs.map((paragraph, position) => (
                    <p key={position} className="whitespace-pre-line">
                        {paragraph}
                    </p>
                ))}
                <dl className="flex flex-col gap-1 rounded-md border bg-background p-2">
                    {Object.entries(email.details).map(([label, value]) => (
                        <div key={label} className="flex justify-between gap-3">
                            <dt className="text-muted-foreground">{label}</dt>
                            <dd className="tabular-nums">{value}</dd>
                        </div>
                    ))}
                </dl>
                <span className="self-start rounded-md bg-primary px-3 py-1.5 text-primary-foreground">
                    View e-invoice
                </span>
            </div>
            {canEditEmail && (
                <p className="text-sm text-muted-foreground">
                    To change the wording for every guest, edit it in{' '}
                    <Link
                        href={emailSettings()}
                        className="underline underline-offset-2"
                    >
                        Settings › Emails
                    </Link>
                    .
                </p>
            )}
        </FormOverlay>
    );
}

function EmailGuestDialog(props: Parameters<typeof EmailGuestForm>[0]) {
    return <EmailGuestForm key={useFreshOnOpen(props.open)} {...props} />;
}

export default function EInvoiceShow({
    submission,
    currency,
    canEditEmail,
}: Props) {
    const [crediting, setCrediting] = useState(false);
    const [emailing, setEmailing] = useState(false);
    const [, copy] = useClipboard();
    const money = (amount: string | null) =>
        amount === null ? (
            '—'
        ) : (
            <MoneyText amount={amount} currency={currency} />
        );
    const title = `${KIND_LABEL[submission.kind]} ${submission.number}`;
    const repairUrl = fixUrl(submission);
    const showCreditNotes =
        submission.kind !== 'credit_note' &&
        (submission.credit_notes.length > 0 || submission.can_credit_note);
    const loginProblem = submission.reasons.some((reason) =>
        reason.fix.includes('E-invoice settings'),
    );

    const copyAndTell = async (text: string, message: string) => {
        if (await copy(text)) {
            toast.add({ title: message, type: 'success' });
        }
    };

    return (
        <>
            <Head title={title} />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title={title}
                    description={
                        submission.buyer?.name ??
                        (submission.kind === 'summary'
                            ? 'General public'
                            : undefined)
                    }
                    back={{ href: index(), label: 'E-invoices' }}
                    badge={<StatusBadge status={submission.status} />}
                    actions={
                        <>
                            {submission.status.value === 'invalid' &&
                                repairUrl && (
                                    <Link
                                        href={repairUrl}
                                        className={buttonVariants()}
                                    >
                                        <Wrench data-icon="inline-start" />
                                        Fix and submit again
                                    </Link>
                                )}
                            {submission.status.value === 'invalid' &&
                                loginProblem && (
                                    <Link
                                        href={eInvoiceSettings()}
                                        className={buttonVariants({
                                            variant: 'outline',
                                        })}
                                    >
                                        E-invoice settings
                                    </Link>
                                )}
                            {submission.status.value === 'invalid' &&
                                submission.kind === 'summary' &&
                                submission.period && (
                                    <Form
                                        {...consolidate.form()}
                                        transform={(data) => ({
                                            ...data,
                                            month: submission.period!.from.slice(
                                                0,
                                                7,
                                            ),
                                        })}
                                        options={{ preserveScroll: true }}
                                    >
                                        {({ processing }) => (
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                                focusableWhenDisabled
                                            >
                                                {processing ? (
                                                    <Spinner data-icon="inline-start" />
                                                ) : (
                                                    <RefreshCw data-icon="inline-start" />
                                                )}
                                                Submit the month again
                                            </Button>
                                        )}
                                    </Form>
                                )}
                            {submission.can_check_status && (
                                <Form
                                    {...refresh.form({
                                        submission: submission.id,
                                    })}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            disabled={processing}
                                            focusableWhenDisabled
                                        >
                                            {processing ? (
                                                <Spinner data-icon="inline-start" />
                                            ) : (
                                                <RefreshCw data-icon="inline-start" />
                                            )}
                                            Check status
                                        </Button>
                                    )}
                                </Form>
                            )}
                            {submission.guest_email && (
                                <Button onClick={() => setEmailing(true)}>
                                    <Mail data-icon="inline-start" />
                                    Email to guest
                                </Button>
                            )}
                            {submission.guest_link && (
                                <>
                                    <a
                                        href={submission.guest_link}
                                        target="_blank"
                                        rel="noreferrer"
                                        className={buttonVariants({
                                            variant: 'outline',
                                        })}
                                    >
                                        View on MyInvois
                                        <ArrowUpRight data-icon="inline-end" />
                                    </a>
                                </>
                            )}
                            {submission.can_credit_note && (
                                <Button
                                    variant="outline"
                                    onClick={() => setCrediting(true)}
                                >
                                    <FilePlus2 data-icon="inline-start" />
                                    Issue credit note
                                </Button>
                            )}
                            {submission.can_cancel && (
                                <CancelAction submission={submission} />
                            )}
                        </>
                    }
                />

                <StatusNotice submission={submission} currency={currency} />

                <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Figure
                        label={
                            submission.kind === 'credit_note'
                                ? 'Credit amount'
                                : 'Total incl. tax'
                        }
                    >
                        <span className="flex flex-col">
                            {money(submission.amounts.total)}
                            {submission.kind !== 'credit_note' &&
                                submission.amounts.tax !== null && (
                                    <span className="text-xs font-normal text-muted-foreground">
                                        Tax {money(submission.amounts.tax)}
                                    </span>
                                )}
                        </span>
                    </Figure>
                    {submission.period && (
                        <Figure
                            label={
                                submission.kind === 'summary' ? 'Month' : 'Stay'
                            }
                        >
                            <DateText date={submission.period.from} /> –{' '}
                            <DateText date={submission.period.to} />
                        </Figure>
                    )}
                    {submission.reservation && (
                        <Figure label="Reservation">
                            <Link
                                href={fixUrl(submission) ?? ''}
                                className="underline underline-offset-2"
                            >
                                {submission.reservation.reference}
                            </Link>
                            <span className="block text-xs font-normal text-muted-foreground">
                                Folio {submission.reservation.folio_number}
                            </span>
                        </Figure>
                    )}
                    {submission.ar_invoice && (
                        <Figure label="City ledger invoice">
                            <Link
                                href={showArInvoice.url({
                                    invoice: submission.ar_invoice.id,
                                })}
                                className="underline underline-offset-2"
                            >
                                {submission.ar_invoice.number}
                            </Link>
                            {submission.ar_invoice.company && (
                                <span className="block text-xs font-normal text-muted-foreground">
                                    {submission.ar_invoice.company}
                                </span>
                            )}
                        </Figure>
                    )}
                    {submission.stays_included !== null && (
                        <Figure label="Stays included">
                            {submission.stays_included}
                        </Figure>
                    )}
                    {submission.original && (
                        <Figure label="Corrects e-invoice">
                            <Link
                                href={show.url({
                                    submission: submission.original.id,
                                })}
                                className="underline underline-offset-2"
                            >
                                {submission.original.number}
                            </Link>
                        </Figure>
                    )}
                    {submission.lhdn_reference && (
                        <Figure label="LHDN reference">
                            <span className="flex items-center gap-1">
                                <span className="truncate font-normal tabular-nums">
                                    {submission.lhdn_reference}
                                </span>
                                <Button
                                    variant="ghost"
                                    size="icon-xs"
                                    aria-label="Copy LHDN reference"
                                    onClick={() =>
                                        copyAndTell(
                                            submission.lhdn_reference!,
                                            'LHDN reference copied',
                                        )
                                    }
                                >
                                    <Copy />
                                </Button>
                            </span>
                        </Figure>
                    )}
                </dl>

                {(submission.buyer || showCreditNotes) && (
                    <div className="grid items-start gap-6 lg:grid-cols-2">
                        {submission.buyer && (
                            <section className="flex flex-col gap-3">
                                <h2 className="text-base font-medium">
                                    Billed to
                                </h2>
                                <dl className="flex flex-col divide-y rounded-lg border">
                                    <Detail label="Name">
                                        {submission.buyer.name}
                                    </Detail>
                                    <Detail label="Tax number (TIN)">
                                        {submission.buyer.tax_number ?? '—'}
                                    </Detail>
                                    {submission.buyer.id_label && (
                                        <Detail
                                            label={submission.buyer.id_label}
                                        >
                                            <span className="tabular-nums">
                                                {submission.buyer.id_number}
                                            </span>
                                        </Detail>
                                    )}
                                    {(submission.buyer.email ||
                                        submission.buyer.phone) && (
                                        <Detail label="Contact">
                                            {[
                                                submission.buyer.email,
                                                submission.buyer.phone,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </Detail>
                                    )}
                                    {submission.buyer.address && (
                                        <Detail label="Address">
                                            {submission.buyer.address}
                                        </Detail>
                                    )}
                                </dl>
                            </section>
                        )}

                        {showCreditNotes && (
                            <section className="flex flex-col gap-3">
                                <h2 className="text-base font-medium">
                                    Credit notes
                                </h2>
                                {submission.credit_notes.length === 0 ? (
                                    <p className="rounded-lg border p-3 text-sm text-muted-foreground">
                                        None. Issue one if the guest should pay
                                        less than this e-invoice.
                                    </p>
                                ) : (
                                    <CreditNoteList
                                        submission={submission}
                                        money={money}
                                    />
                                )}
                            </section>
                        )}
                    </div>
                )}

                {submission.history.length > 0 && (
                    <Collapsible className="rounded-lg border">
                        <CollapsibleTrigger
                            render={
                                <button
                                    type="button"
                                    className="group/history flex w-full items-center justify-between gap-2 p-3 text-left text-sm font-medium"
                                />
                            }
                        >
                            <span>
                                History
                                <span className="ms-2 font-normal text-muted-foreground">
                                    {submission.history.length}{' '}
                                    {submission.history.length === 1
                                        ? 'event'
                                        : 'events'}
                                    {' · last: '}
                                    {
                                        submission.history[
                                            submission.history.length - 1
                                        ].label
                                    }
                                </span>
                            </span>
                            <ChevronDown className="size-4 shrink-0 transition-transform group-data-[panel-open]/history:rotate-180" />
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <ol className="flex flex-col divide-y border-t">
                                {submission.history.map((event, position) => (
                                    <li
                                        key={position}
                                        className="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm"
                                    >
                                        <span>{event.label}</span>
                                        <span className="text-muted-foreground tabular-nums">
                                            {event.at}
                                        </span>
                                    </li>
                                ))}
                            </ol>
                        </CollapsibleContent>
                    </Collapsible>
                )}
            </div>

            {submission.guest_email && (
                <EmailGuestDialog
                    open={emailing}
                    onOpenChange={setEmailing}
                    submission={submission}
                    canEditEmail={canEditEmail}
                />
            )}
            {submission.can_credit_note && (
                <CreditNoteDialog
                    open={crediting}
                    onOpenChange={setCrediting}
                    submission={submission}
                    currency={currency}
                />
            )}
        </>
    );
}
