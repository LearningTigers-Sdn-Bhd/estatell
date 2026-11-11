import { Form, Head } from '@inertiajs/react';
import { RotateCcw } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/pms/page-header';
import { SettingsSection } from '@/components/pms/settings-section';
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
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { edit, reset, update } from '@/routes/settings/emails';

type Placeholder = { key: string; label: string; example: string };

type GuestEmail = {
    value: string;
    label: string;
    description: string;
    placeholders: Placeholder[];
    default: { subject: string; body: string };
    subject: string;
    body: string;
    customised: boolean;
};

/** Display only: the server fills in the real values when it sends. */
function withExamples(text: string, placeholders: Placeholder[]): string {
    return placeholders.reduce(
        (filled, placeholder) =>
            filled.replaceAll(`{${placeholder.key}}`, placeholder.example),
        text,
    );
}

function ResetAction({ email }: { email: GuestEmail }) {
    const [open, setOpen] = useState(false);

    return (
        <AlertDialog open={open} onOpenChange={setOpen}>
            <AlertDialogTrigger render={<Button variant="outline" size="sm" />}>
                <RotateCcw data-icon="inline-start" />
                Reset to default
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Reset {email.label}?</AlertDialogTitle>
                    <AlertDialogDescription>
                        Your subject and message are replaced by the default
                        text. Emails already sent are not changed.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <Form
                    {...reset.form({ email: email.value })}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                >
                    {({ processing }) => (
                        <AlertDialogFooter>
                            <AlertDialogCancel>Keep my text</AlertDialogCancel>
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={processing}
                                focusableWhenDisabled
                            >
                                {processing && (
                                    <Spinner data-icon="inline-start" />
                                )}
                                Reset to default
                            </Button>
                        </AlertDialogFooter>
                    )}
                </Form>
            </AlertDialogContent>
        </AlertDialog>
    );
}

function EmailSection({ email }: { email: GuestEmail }) {
    const [subject, setSubject] = useState(email.subject);
    const [body, setBody] = useState(email.body);
    const paragraphs = withExamples(body, email.placeholders)
        .split(/\n{2,}/)
        .map((paragraph) => paragraph.trim())
        .filter(Boolean);

    return (
        <SettingsSection
            form={update.form({ email: email.value })}
            title={email.label}
            description={email.description}
            submitLabel="Save email"
            contentClassName="grid gap-6 lg:grid-cols-2"
            actions={email.customised ? <ResetAction email={email} /> : null}
        >
            {(errors) => (
                <>
                    <div className="flex flex-col gap-5">
                        <Field data-invalid={!!errors.subject}>
                            <FieldLabel htmlFor="subject">Subject</FieldLabel>
                            <Input
                                id="subject"
                                name="subject"
                                value={subject}
                                onChange={(event) =>
                                    setSubject(event.target.value)
                                }
                                maxLength={200}
                                aria-invalid={!!errors.subject}
                            />
                            <FieldError>{errors.subject}</FieldError>
                        </Field>
                        <Field data-invalid={!!errors.body}>
                            <FieldLabel htmlFor="body">Message</FieldLabel>
                            <Textarea
                                id="body"
                                name="body"
                                value={body}
                                onChange={(event) =>
                                    setBody(event.target.value)
                                }
                                rows={10}
                                maxLength={5000}
                                aria-invalid={!!errors.body}
                            />
                            <FieldDescription>
                                Leave an empty line between paragraphs.
                            </FieldDescription>
                            <FieldError>{errors.body}</FieldError>
                        </Field>
                        <div className="flex flex-col gap-2">
                            <p className="text-sm font-medium">Placeholders</p>
                            <p className="text-sm text-muted-foreground">
                                Type one, braces included, and each guest's
                                details go in its place.
                            </p>
                            <dl className="flex flex-col divide-y rounded-lg border text-sm">
                                {email.placeholders.map((placeholder) => (
                                    <div
                                        key={placeholder.key}
                                        className="grid gap-1 px-3 py-2 sm:grid-cols-2 sm:gap-4"
                                    >
                                        <dt className="font-mono">
                                            {`{${placeholder.key}}`}
                                        </dt>
                                        <dd className="text-muted-foreground">
                                            {placeholder.label}, such as{' '}
                                            {placeholder.example}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </div>
                    </div>

                    <div className="flex flex-col gap-2">
                        <p className="text-sm font-medium">Preview</p>
                        <p className="text-sm text-muted-foreground">
                            With example details. The button and the e-invoice
                            details are always added.
                        </p>
                        <div className="flex flex-col gap-3 rounded-lg border bg-muted/40 p-4 text-sm">
                            <p className="font-medium">
                                {withExamples(subject, email.placeholders)}
                            </p>
                            {paragraphs.map((paragraph, position) => (
                                <p
                                    key={position}
                                    className="whitespace-pre-line"
                                >
                                    {paragraph}
                                </p>
                            ))}
                            <span className="self-start rounded-md bg-primary px-3 py-1.5 text-primary-foreground">
                                View e-invoice
                            </span>
                        </div>
                    </div>
                </>
            )}
        </SettingsSection>
    );
}

export default function EmailSettings({ emails }: { emails: GuestEmail[] }) {
    return (
        <>
            <Head title="Emails" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Emails"
                    description="The wording of the emails your property sends to guests. Changes apply to the next email sent."
                />

                {emails.map((email) => (
                    <EmailSection
                        key={`${email.value}-${email.customised}-${email.subject}`}
                        email={email}
                    />
                ))}
            </div>
        </>
    );
}

EmailSettings.layout = {
    breadcrumbs: [{ title: 'Emails', href: edit() }],
};
