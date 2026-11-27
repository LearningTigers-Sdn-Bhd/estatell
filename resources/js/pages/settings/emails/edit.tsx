import { Head, useForm, useHttp } from '@inertiajs/react';
import { Eye, Mail, MoreHorizontal, RotateCcw, Save } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { EmailPreview } from '@/components/pms/email-preview';
import { PageHeader } from '@/components/pms/page-header';
import { PanelHeading } from '@/components/pms/panel-heading';
import { SaveBar } from '@/components/pms/save-bar';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Workspace } from '@/components/pms/workspace';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import { edit, index, preview, reset, update } from '@/routes/settings/emails';

type Placeholder = { key: string; label: string; example: string };

type GuestEmail = {
    value: string;
    label: string;
    module: string;
    description: string;
    placeholders: Placeholder[];
    subject: string;
    body: string;
    customised: boolean;
};

type Preview = { subject: string; reply_to: string | null; html: string };

/** How long typing must pause before the preview asks the server again. */
const PREVIEW_DELAY_MS = 400;

function ResetDialog({
    email,
    open,
    onOpenChange,
}: {
    email: GuestEmail;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm({});

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Reset {email.label}?</AlertDialogTitle>
                    <AlertDialogDescription>
                        Your subject and message are replaced by the default
                        text. Emails already sent are not changed.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.delete(reset.url({ email: email.value }), {
                            preserveScroll: true,
                            onSuccess: () => onOpenChange(false),
                        });
                    }}
                >
                    <AlertDialogFooter>
                        <AlertDialogCancel>Keep my text</AlertDialogCancel>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={form.processing}
                            focusableWhenDisabled
                        >
                            {form.processing && (
                                <Spinner data-icon="inline-start" />
                            )}
                            Reset to default
                        </Button>
                    </AlertDialogFooter>
                </form>
            </AlertDialogContent>
        </AlertDialog>
    );
}

/**
 * The preview of the wording being edited. It asks the server once typing pauses, and only shows the answer for the
 * latest text, so a slow answer never replaces a newer one.
 */
function useLivePreview(
    email: GuestEmail,
    subject: string,
    body: string,
    initial: Preview,
) {
    const http = useHttp<{ subject: string; body: string }, Preview>({
        subject: '',
        body: '',
    });
    const [shown, setShown] = useState({
        for: `${email.subject}\u0000${email.body}`,
        value: initial,
    });
    const asked = `${subject}\u0000${body}`;

    useEffect(() => {
        if (asked === shown.for) {
            return;
        }

        const timer = window.setTimeout(() => {
            http.transform(() => ({ subject, body }));
            http.post(preview.url({ email: email.value }))
                .then((value) => setShown({ for: asked, value }))
                .catch(() => undefined);
        }, PREVIEW_DELAY_MS);

        return () => window.clearTimeout(timer);
        // `http` is a new object each render; the request only depends on the text.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [asked]);

    return { preview: shown.value, stale: asked !== shown.for };
}

/**
 * The form for one email, with the preview in the Workspace rail. Save sits in the floating Save bar while the text
 * has changed. The page remounts it (by key) when the saved text changes, so it always starts from the server copy.
 */
function GuestEmailEditor({
    email,
    initialPreview,
}: {
    email: GuestEmail;
    initialPreview: Preview;
}) {
    const form = useForm({ subject: email.subject, body: email.body });
    const dirty =
        form.data.subject !== email.subject || form.data.body !== email.body;
    const guard = useUnsavedChanges(dirty);
    const { preview: shown, stale } = useLivePreview(
        email,
        form.data.subject,
        form.data.body,
        initialPreview,
    );
    const subjectRef = useRef<HTMLInputElement>(null);
    const bodyRef = useRef<HTMLTextAreaElement>(null);
    const lastField = useRef<'subject' | 'body'>('body');

    const save = (onSuccess?: () => void) =>
        form.put(update.url({ email: email.value }), {
            preserveScroll: true,
            onSuccess,
        });

    /** Puts the placeholder where the cursor was, in the field last used. */
    const insert = (key: string) => {
        const field = lastField.current;
        const element =
            field === 'subject' ? subjectRef.current : bodyRef.current;
        const text = form.data[field];
        const token = `{${key}}`;
        const start = element?.selectionStart ?? text.length;
        const end = element?.selectionEnd ?? text.length;

        form.setData(field, text.slice(0, start) + token + text.slice(end));
        window.requestAnimationFrame(() => {
            element?.focus();
            element?.setSelectionRange(
                start + token.length,
                start + token.length,
            );
        });
    };

    return (
        <Workspace
            asideLabel="Preview"
            asideWidth="email"
            aside={
                <section className="flex flex-col gap-4">
                    <PanelHeading
                        icon={Eye}
                        title="Preview"
                        description="What the guest gets, with example details."
                    />
                    <EmailPreview
                        subject={shown.subject}
                        replyTo={shown.reply_to}
                        html={shown.html}
                        loading={stale}
                    />
                </section>
            }
        >
            <form
                className="flex flex-col gap-5"
                onSubmit={(event) => {
                    event.preventDefault();
                    save();
                }}
            >
                <PanelHeading
                    icon={Mail}
                    title="Wording"
                    description={email.description}
                />
                <div className="flex flex-col gap-5 px-2 pb-2">
                    <Field data-invalid={!!form.errors.subject}>
                        <FieldLabel htmlFor="subject">Subject</FieldLabel>
                        <Input
                            ref={subjectRef}
                            id="subject"
                            value={form.data.subject}
                            onChange={(event) =>
                                form.setData('subject', event.target.value)
                            }
                            onFocus={() => (lastField.current = 'subject')}
                            maxLength={200}
                            aria-invalid={!!form.errors.subject}
                        />
                        <FieldError>{form.errors.subject}</FieldError>
                    </Field>
                    <Field data-invalid={!!form.errors.body}>
                        <FieldLabel htmlFor="body">Message</FieldLabel>
                        <Textarea
                            ref={bodyRef}
                            id="body"
                            value={form.data.body}
                            onChange={(event) =>
                                form.setData('body', event.target.value)
                            }
                            onFocus={() => (lastField.current = 'body')}
                            rows={12}
                            maxLength={5000}
                            aria-invalid={!!form.errors.body}
                        />
                        <FieldDescription>
                            Leave an empty line between paragraphs.
                        </FieldDescription>
                        <FieldError>{form.errors.body}</FieldError>
                    </Field>
                    <div className="flex flex-col gap-2">
                        <p className="text-sm font-medium">Placeholders</p>
                        <p className="text-sm text-muted-foreground">
                            Press one to add it where the cursor is. Each
                            guest&apos;s details go in its place.
                        </p>
                        <div className="overflow-auto rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Placeholder</TableHead>
                                        <TableHead>Replaced with</TableHead>
                                        <TableHead>Example</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {email.placeholders.map((placeholder) => (
                                        <TableRow
                                            key={placeholder.key}
                                            className="relative"
                                        >
                                            <TableCell>
                                                <button
                                                    type="button"
                                                    onMouseDown={(event) =>
                                                        event.preventDefault()
                                                    }
                                                    onClick={() =>
                                                        insert(placeholder.key)
                                                    }
                                                    aria-label={`Add {${placeholder.key}}`}
                                                    className="rounded-sm font-mono outline-none after:absolute after:inset-0 focus-visible:ring-2 focus-visible:ring-ring"
                                                >
                                                    {`{${placeholder.key}}`}
                                                </button>
                                            </TableCell>
                                            <TableCell className="whitespace-normal">
                                                {placeholder.label}
                                            </TableCell>
                                            <TableCell className="whitespace-normal text-muted-foreground">
                                                {placeholder.example}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </div>
                </div>

                {dirty && (
                    <SaveBar
                        name={email.label}
                        processing={form.processing}
                        onDiscard={() => {
                            form.setData({
                                subject: email.subject,
                                body: email.body,
                            });
                            form.clearErrors();
                        }}
                        saveButton={
                            <Button
                                type="submit"
                                disabled={form.processing}
                                focusableWhenDisabled
                            >
                                {form.processing ? (
                                    <Spinner data-icon="inline-start" />
                                ) : (
                                    <Save data-icon="inline-start" />
                                )}
                                Save email
                            </Button>
                        }
                    />
                )}
            </form>

            <UnsavedChangesDialog
                guard={guard}
                name={email.label}
                saving={form.processing}
                onSave={() => save(guard.proceed)}
            />
        </Workspace>
    );
}

/**
 * Settings › Emails › {email}. The subject and message of one guest email, in a fixed-height `Workspace` with the
 * preview in the rail. The preview is the real email, rendered by the server. More actions holds Reset to default
 * while the property has its own text.
 */
export default function GuestEmailEdit({
    email,
    preview: initialPreview,
}: {
    email: GuestEmail;
    preview: Preview;
}) {
    const [resetting, setResetting] = useState(false);

    return (
        <>
            <Head title={email.label} />
            <div className="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto p-4 lg:overflow-hidden">
                <PageHeader
                    title={email.label}
                    description={`${email.module}. Changes apply to the next email sent.`}
                    back={{ href: index.url(), label: 'Emails' }}
                    badge={
                        email.customised ? (
                            <Badge variant="secondary">Edited</Badge>
                        ) : (
                            <Badge variant="outline">Default</Badge>
                        )
                    }
                    actions={
                        email.customised ? (
                            <DropdownMenu>
                                <DropdownMenuTrigger
                                    render={<Button variant="outline" />}
                                >
                                    <MoreHorizontal data-icon="inline-start" />
                                    More actions
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        variant="destructive"
                                        onClick={() => setResetting(true)}
                                    >
                                        <RotateCcw />
                                        Reset to default
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        ) : undefined
                    }
                />
                <div className="flex flex-col lg:min-h-0 lg:flex-1">
                    <GuestEmailEditor
                        key={`${email.customised}\u0000${email.subject}\u0000${email.body}`}
                        email={email}
                        initialPreview={initialPreview}
                    />
                </div>
            </div>
            {email.customised && (
                <ResetDialog
                    email={email}
                    open={resetting}
                    onOpenChange={setResetting}
                />
            )}
        </>
    );
}

GuestEmailEdit.layout = (props: { email: GuestEmail }) => ({
    variant: 'fixed',
    breadcrumbs: [
        { title: 'Emails', href: index() },
        { title: props.email.label, href: edit({ email: props.email.value }) },
    ],
});
