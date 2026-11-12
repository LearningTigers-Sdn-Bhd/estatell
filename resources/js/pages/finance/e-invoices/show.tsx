import { Form, Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowUpRight,
    ChevronDown,
    Copy,
    FilePlus2,
    Mail,
    RefreshCw,
} from 'lucide-react';
import { useState } from 'react';
import { DateText } from '@/components/pms/date-text';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Spinner } from '@/components/ui/spinner';
import { toast } from '@/components/ui/toast';
import { useClipboard } from '@/hooks/use-clipboard';
import { show as showArInvoice } from '@/routes/finance/city-ledger/invoices';
import { consolidate, index, refresh, show } from '@/routes/finance/e-invoices';
import {
    KIND_LABEL,
    type Submission,
    folioUrl,
    isAdjustment,
} from '@/components/pms/einvoice/submission';
import { Detail, Figure } from '@/components/pms/einvoice/submission-details';
import { CreditNoteList } from '@/components/pms/einvoice/credit-note-list';
import { SummaryStays } from '@/components/pms/einvoice/summary-stays';
import { StatusNotice } from '@/components/pms/einvoice/status-notice';
import { CancelAction } from '@/components/pms/einvoice/cancel-action';
import { CreditNoteDialog } from '@/components/pms/einvoice/credit-note-dialog';
import { EmailGuestDialog } from '@/components/pms/einvoice/email-guest-dialog';
import { RejectedActions } from '@/components/pms/einvoice/rejected-actions';

type Props = {
    submission: Submission;
    currency: App.Data.Property.CurrencyData | null;
    canEditEmail: boolean;
};

export default function EInvoiceShow({
    submission,
    currency,
    canEditEmail,
}: Props) {
    const pageUrl = usePage().url;
    // A rejected credit note links here to issue a new one.
    const [crediting, setCrediting] = useState(
        () =>
            submission.can_credit_note &&
            new URLSearchParams(pageUrl.split('?')[1] ?? '').get(
                'credit_note',
            ) === 'new',
    );
    const [emailing, setEmailing] = useState(false);
    const [, copy] = useClipboard();
    const money = (amount: string | null) =>
        amount === null ? (
            '—'
        ) : (
            <MoneyText amount={amount} currency={currency} />
        );
    const title = `${KIND_LABEL[submission.kind]} ${submission.number}`;
    const showCreditNotes =
        !isAdjustment(submission) &&
        (submission.credit_notes.length > 0 || submission.can_credit_note);

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
                                !submission.replaced_by && (
                                    <RejectedActions submission={submission} />
                                )}
                            {submission.status.value === 'invalid' &&
                                !submission.replaced_by &&
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
                                    Issue credit or refund note
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
                            isAdjustment(submission)
                                ? `${KIND_LABEL[submission.kind]} amount`
                                : 'Total incl. tax'
                        }
                    >
                        <span className="flex flex-col">
                            {money(submission.amounts.total)}
                            {!isAdjustment(submission) &&
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
                                href={folioUrl(submission) ?? ''}
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
                                    Credit and refund notes
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

                {submission.stays.length > 0 && (
                    <SummaryStays submission={submission} currency={currency} />
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
