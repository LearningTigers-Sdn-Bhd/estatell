import { Link, useForm } from '@inertiajs/react';
import { FormOverlay } from '@/components/pms/form-overlay';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { email as emailRoute } from '@/routes/finance/e-invoices';
import { edit as emailSettings } from '@/routes/settings/emails';
import { type Submission } from '@/components/pms/einvoice/submission';

export function EmailGuestForm({
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

export function EmailGuestDialog(props: Parameters<typeof EmailGuestForm>[0]) {
    return <EmailGuestForm key={useFreshOnOpen(props.open)} {...props} />;
}
