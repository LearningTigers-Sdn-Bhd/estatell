import { Form } from '@inertiajs/react';
import { StatusBadge } from '@/components/pms/status-badge';
import {
    ArrowUpRight,
    CircleCheck,
    FileText,
    MessageSquare,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { refund } from '@/routes/concierge';

/** The links to a stay's PDFs: the booking summary, receipts and the final invoice. */
export function DocumentList({
    documents,
}: {
    documents: App.Data.Concierge.ConciergeDocumentData[];
}) {
    if (documents.length === 0) {
        return null;
    }

    return (
        <section className="flex flex-col gap-3">
            <h2 className="text-lg font-semibold">Your documents</h2>
            <p className="text-sm text-muted-foreground">
                Open or download a PDF for your records.
            </p>
            <ul className="grid gap-3 sm:grid-cols-2">
                {documents.map((document) => (
                    <li key={document.url}>
                        <a
                            href={document.url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="flex h-full min-h-20 items-center gap-4 rounded-2xl border border-border/40 bg-card p-4 text-sm transition-colors hover:bg-muted/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                        >
                            <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted/50 text-primary">
                                <FileText className="size-5" aria-hidden />
                            </span>
                            <span className="flex min-w-0 flex-1 flex-col gap-1">
                                <span className="font-medium text-pretty">
                                    {document.label}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    PDF document
                                </span>
                            </span>
                            <ArrowUpRight
                                className="size-4 shrink-0 text-muted-foreground"
                                aria-hidden
                            />
                            <span className="sr-only">Opens in a new tab</span>
                        </a>
                    </li>
                ))}
            </ul>
        </section>
    );
}

/** After check-out: a thank-you, the stay's documents, and a refund request when the property accepts them. */
export function CheckedOutStep({
    stay,
}: {
    stay: App.Data.Concierge.ConciergeStayData;
}) {
    return (
        <div className="flex flex-col gap-8">
            <div className="flex items-start gap-4 rounded-2xl bg-muted/50 p-6 sm:p-8">
                <CircleCheck
                    className="mt-1 size-8 shrink-0 text-primary"
                    strokeWidth={1.5}
                    aria-hidden
                />
                <div className="flex flex-col gap-2">
                    <h2 className="text-2xl font-semibold">
                        You’re checked out
                    </h2>
                    <p className="text-sm leading-relaxed text-pretty text-muted-foreground">
                        Thank you for staying with us. Your documents are
                        available below.
                    </p>
                </div>
            </div>

            <DocumentList documents={stay.documents} />

            {stay.refundRequest && (
                <section className="flex max-w-2xl flex-col gap-5 rounded-2xl border border-border/40 bg-card p-6">
                    <h2 className="text-lg font-semibold">
                        Your refund request
                    </h2>
                    <div className="flex flex-col gap-2 rounded-lg border p-4">
                        <div className="flex items-center justify-between gap-3">
                            <p className="text-sm text-pretty">
                                {stay.refundRequest.details}
                            </p>
                            <StatusBadge status={stay.refundRequest.status} />
                        </div>
                        {stay.refundRequest.note && (
                            <p className="text-sm text-pretty text-muted-foreground">
                                {stay.refundRequest.note}
                            </p>
                        )}
                    </div>
                </section>
            )}

            {stay.canRequestRefund && (
                <section className="flex max-w-2xl flex-col gap-5 rounded-2xl border border-border/40 bg-card p-6">
                    <h2 className="flex items-center gap-2 text-lg font-semibold">
                        <MessageSquare
                            className="size-5 text-primary"
                            aria-hidden
                        />
                        Request a refund
                    </h2>
                    <p className="text-sm text-pretty text-muted-foreground">
                        Tell the front desk what you’d like refunded and why.
                        This sends a request for the team to review.
                    </p>
                    <Form
                        action={refund.url({ token: stay.token })}
                        method="post"
                        resetOnSuccess
                    >
                        {({ processing, errors }) => (
                            <div className="flex flex-col gap-4">
                                <Field data-invalid={!!errors.details}>
                                    <FieldLabel htmlFor="details">
                                        Details
                                    </FieldLabel>
                                    <Textarea
                                        id="details"
                                        name="details"
                                        maxLength={1000}
                                        required
                                        aria-invalid={!!errors.details}
                                        className="min-h-32 text-base"
                                    />
                                    <FieldError>{errors.details}</FieldError>
                                </Field>
                                <Button
                                    type="submit"
                                    size="lg"
                                    className="h-12 w-full rounded-lg"
                                    disabled={processing}
                                    focusableWhenDisabled
                                >
                                    {processing && (
                                        <Spinner data-icon="inline-start" />
                                    )}
                                    Send refund request
                                </Button>
                            </div>
                        )}
                    </Form>
                </section>
            )}
        </div>
    );
}
