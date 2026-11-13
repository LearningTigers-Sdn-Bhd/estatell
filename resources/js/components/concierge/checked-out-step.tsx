import { Form } from '@inertiajs/react';
import { StatusBadge } from '@/components/pms/status-badge';
import { FileText } from 'lucide-react';
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
            <ul className="flex flex-col gap-2">
                {documents.map((document) => (
                    <li key={document.url}>
                        <a
                            href={document.url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="flex items-center gap-3 rounded-lg border p-3 text-sm transition-colors hover:bg-muted"
                        >
                            <FileText
                                className="size-5 shrink-0 text-muted-foreground"
                                aria-hidden
                            />
                            <span className="text-pretty">
                                {document.label}
                            </span>
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
        <div className="flex max-w-2xl flex-col gap-6">
            <div className="flex flex-col gap-2 rounded-lg border p-4">
                <h2 className="text-lg font-semibold">You have checked out</h2>
                <p className="text-sm text-pretty text-muted-foreground">
                    Thank you for staying with us. We hope to see you again.
                </p>
            </div>

            <DocumentList documents={stay.documents} />

            {stay.refundRequest && (
                <section className="flex flex-col gap-3">
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
                <section className="flex flex-col gap-3">
                    <h2 className="text-lg font-semibold">Ask for a refund</h2>
                    <p className="text-sm text-pretty text-muted-foreground">
                        Tell the property what you are asking for and why. The
                        property replies to you.
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
