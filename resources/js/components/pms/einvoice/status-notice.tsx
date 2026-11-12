import { Link } from '@inertiajs/react';
import { Ban, CircleAlert, CircleCheck, Clock } from 'lucide-react';
import { MoneyText } from '@/components/pms/money-text';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { show } from '@/routes/finance/e-invoices';
import {
    KIND_LABEL,
    type Submission,
    isAdjustment,
    rejection,
} from '@/components/pms/einvoice/submission';
import { FindTaxNumber } from '@/components/pms/einvoice/find-tax-number';

/** One line under the reasons that says where the details live. */
export function WhereToFix({ submission }: { submission: Submission }) {
    const needs = rejection(submission);

    if (isAdjustment(submission) && submission.original) {
        return (
            <p>
                A {KIND_LABEL[submission.kind].toLowerCase()} uses the buyer
                from the original e-invoice. Open{' '}
                <strong>{submission.original.number}</strong> and issue a new
                one.
            </p>
        );
    }

    if (!needs.buyer || !submission.can_resubmit) {
        return null;
    }

    if (submission.ar_invoice) {
        return (
            <p>
                A city ledger e-invoice uses the company's tax numbers and
                billing address. Correct them on the company page, then press{' '}
                <strong>Submit again</strong>.
            </p>
        );
    }

    if (submission.reservation) {
        return (
            <p>
                The buyer details are in the <strong>Issue e-invoice</strong>{' '}
                form on folio {submission.reservation.folio_number}.{' '}
                <strong>Fix and submit again</strong> opens it with what was
                sent.
            </p>
        );
    }

    return null;
}

/**
 * The one thing a manager needs to know about this document, and what to do next. Dates, amounts and button names
 * are bold so the eye lands on them.
 */
export function StatusNotice({
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
                        {isAdjustment(submission) && (
                            <>
                                {submission.kind === 'refund_note'
                                    ? 'It records money paid back on e-invoice'
                                    : 'It reduces e-invoice'}{' '}
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
                            that, correct it with a credit or refund note.
                        </p>
                    )}
                </AlertDescription>
            </Alert>
        );
    }

    if (submission.replaced_by) {
        const next = submission.replaced_by;

        return (
            <Alert>
                <CircleCheck className="text-success" />
                <AlertTitle>
                    Rejected by LHDN, then sent again. Nothing more to do.
                </AlertTitle>
                <AlertDescription>
                    <p>
                        It went again as {next.kind.label.toLowerCase()}{' '}
                        <Link
                            href={show.url({ submission: next.id })}
                            className="font-medium underline underline-offset-2"
                        >
                            {next.number}
                        </Link>{' '}
                        ({next.status.label.toLowerCase()}).
                    </p>
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
                    <WhereToFix submission={submission} />
                    {submission.can_find_tax_number && (
                        <FindTaxNumber submission={submission} />
                    )}
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

    if (status.value === 'unconfirmed') {
        return (
            <Alert>
                <Clock className="text-warning" />
                <AlertTitle>Waiting for LHDN. Do not send it again.</AlertTitle>
                <AlertDescription>
                    <p>
                        We lost the connection while sending it, so LHDN may
                        have it. We are checking with LHDN by its number, and
                        the status updates by itself within 15 minutes.
                    </p>
                    <p>
                        Press <strong>Check status</strong> to check now.
                    </p>
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
