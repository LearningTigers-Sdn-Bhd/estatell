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
import { EmailPreview } from '@/components/pms/email-preview';
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
            description="The guest gets this email. The View e-invoice button opens their copy on MyInvois."
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
            <div className="max-h-96 overflow-y-auto rounded-xl">
                <EmailPreview
                    subject={email.subject}
                    replyTo={email.reply_to}
                    html={email.html}
                />
            </div>
            {canEditEmail && (
                <p className="text-sm text-muted-foreground">
                    To change the wording for every guest, edit it in{' '}
                    <Link
                        href={emailSettings({ email: 'e_invoice' })}
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
