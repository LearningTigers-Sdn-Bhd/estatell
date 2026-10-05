import { router, useForm } from '@inertiajs/react';
import { History, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import {
    TermsPreview,
    countChecklistItems,
} from '@/components/pms/registration-card/terms-preview';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import { terms } from '@/routes/settings/registration-card';
import {
    destroy,
    store,
} from '@/routes/settings/registration-card/terms/documents';
import { apply } from '@/routes/settings/registration-card/terms/preset';
import { store as storeVersion } from '@/routes/settings/registration-card/terms/versions';

type Document = App.Data.Registration.TermsDocumentData;
type Editor = App.Data.Registration.TermsEditorData;

const MAX_CHECKLIST_ITEMS = 10;

const formatDate = (iso: string) =>
    new Date(iso).toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });

/** Opens a document in a language. Only the editor data is reloaded. */
const openEditor = (documentId: number, locale: string) =>
    router.get(
        terms.url(undefined, { query: { document: documentId, locale } }),
        {},
        {
            only: ['termsEditor'],
            preserveScroll: true,
            preserveState: true,
            replace: true,
        },
    );

function AddDocumentDialog({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm({ name: '' });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(store.url(), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onOpenChange(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>Add terms document</DialogTitle>
                        <DialogDescription>
                            It starts as a copy of the template for your
                            property type. Assign it to room types on the room
                            type form.
                        </DialogDescription>
                    </DialogHeader>
                    <Field>
                        <FieldLabel htmlFor="terms-document-name">
                            Name
                        </FieldLabel>
                        <Input
                            id="terms-document-name"
                            maxLength={100}
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            aria-invalid={!!form.errors.name}
                            autoFocus
                        />
                        <FieldError>{form.errors.name}</FieldError>
                    </Field>
                    <DialogFooter>
                        <Button
                            type="submit"
                            disabled={form.processing}
                            focusableWhenDisabled
                        >
                            {form.processing && <Spinner />}
                            Add document
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function DeleteDocumentDialog({
    document,
    open,
    onOpenChange,
}: {
    document: Document;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm({});

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Delete {document.name}?</AlertDialogTitle>
                    <AlertDialogDescription>
                        All its versions, in every language, are deleted.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.delete(
                            destroy.url({ termsDocument: document.id }),
                            { onSuccess: () => onOpenChange(false) },
                        );
                    }}
                >
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={form.processing}
                            focusableWhenDisabled
                        >
                            {form.processing && <Spinner />}
                            Delete document
                        </Button>
                    </AlertDialogFooter>
                </form>
            </AlertDialogContent>
        </AlertDialog>
    );
}

/**
 * The editor for one document in one language. Remounted (by key) whenever the document, language
 * or latest version changes, so its text always starts from the server copy.
 */
function TermsEditor({
    editor,
    document,
}: {
    editor: Editor;
    document: Document | undefined;
}) {
    const form = useForm({ locale: editor.locale, body: editor.body });
    const [historyOpen, setHistoryOpen] = useState(false);
    const [templateOpen, setTemplateOpen] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const applyForm = useForm({ locale: editor.locale });
    const dirty = form.data.body !== editor.body;
    const guard = useUnsavedChanges(dirty);
    const items = countChecklistItems(form.data.body);

    const publish = (onSuccess?: () => void) =>
        form.post(storeVersion.url({ termsDocument: editor.documentId }), {
            preserveScroll: true,
            onSuccess,
        });

    return (
        <div className="grid gap-4 lg:grid-cols-3">
            <section className="flex flex-col gap-3 lg:col-span-2">
                <p className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                    {editor.isDraft ? (
                        <>
                            <Badge variant="outline">Draft</Badge>
                            Not published in this language yet. It starts from
                            the {editor.presetLabel} template.
                        </>
                    ) : (
                        <>
                            <span className="font-medium text-foreground tabular-nums">
                                v{editor.version}
                            </span>
                            published{' '}
                            {editor.publishedAt && (
                                <time dateTime={editor.publishedAt}>
                                    {formatDate(editor.publishedAt)}
                                </time>
                            )}
                            {editor.publishedBy && <>by {editor.publishedBy}</>}
                            {editor.presetLabel && (
                                <Badge variant="secondary">
                                    {editor.presetLabel} template
                                </Badge>
                            )}
                        </>
                    )}
                </p>
                <Field>
                    <FieldLabel htmlFor="terms-body">Terms text</FieldLabel>
                    <Textarea
                        id="terms-body"
                        className="min-h-96 font-mono"
                        value={form.data.body}
                        onChange={(event) =>
                            form.setData('body', event.target.value)
                        }
                        aria-invalid={!!form.errors.body}
                    />
                    <FieldError>{form.errors.body}</FieldError>
                    <FieldDescription>
                        Placeholders:{' '}
                        {Object.keys(editor.placeholderValues)
                            .map((name) => `{{${name}}}`)
                            .join(', ')}
                        . A line that starts with &quot;- [ ]&quot; becomes a
                        tick box the guest must tick before signing (
                        <span
                            className={
                                items > MAX_CHECKLIST_ITEMS
                                    ? 'text-destructive'
                                    : undefined
                            }
                        >
                            {items} of {MAX_CHECKLIST_ITEMS}
                        </span>
                        ). Templates are a starting point, not legal advice.
                    </FieldDescription>
                </Field>
                {editor.newerTemplateBody !== null && (
                    <Alert>
                        <RefreshCw aria-hidden />
                        <AlertTitle>A newer template is available.</AlertTitle>
                        <AlertDescription className="flex flex-wrap items-center gap-2">
                            Applying it makes a new version. Nothing is lost.
                            <span className="flex gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setTemplateOpen(true)}
                                >
                                    Preview
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    disabled={applyForm.processing}
                                    focusableWhenDisabled
                                    onClick={() =>
                                        applyForm.post(
                                            apply.url({
                                                termsDocument:
                                                    editor.documentId,
                                            }),
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    {applyForm.processing && <Spinner />}
                                    Apply
                                </Button>
                            </span>
                        </AlertDescription>
                    </Alert>
                )}
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setHistoryOpen(true)}
                        >
                            <History data-icon="inline-start" />
                            Version history
                        </Button>
                        {document?.canDelete && (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => setDeleting(true)}
                            >
                                <Trash2 data-icon="inline-start" />
                                Delete document
                            </Button>
                        )}
                    </div>
                    <Button
                        type="button"
                        disabled={
                            form.processing ||
                            (!editor.isDraft && !dirty) ||
                            items > MAX_CHECKLIST_ITEMS
                        }
                        focusableWhenDisabled
                        onClick={() => publish()}
                    >
                        {form.processing && <Spinner />}
                        Publish new version
                    </Button>
                </div>
            </section>

            <aside className="flex flex-col gap-3 rounded-lg border p-4 lg:self-start">
                <h4 className="text-[0.65rem] font-medium tracking-wide text-muted-foreground uppercase">
                    Preview
                </h4>
                <TermsPreview
                    body={form.data.body}
                    values={editor.placeholderValues}
                />
            </aside>

            <Sheet open={historyOpen} onOpenChange={setHistoryOpen}>
                <SheetContent className="data-[side=right]:sm:max-w-md">
                    <SheetHeader>
                        <SheetTitle>Version history</SheetTitle>
                        <SheetDescription>
                            Published versions never change. Use one as a
                            starting point, then publish it as a new version.
                        </SheetDescription>
                    </SheetHeader>
                    <ul className="flex flex-1 flex-col gap-3 overflow-y-auto px-4 pb-4">
                        {editor.history.length === 0 && (
                            <li className="text-sm text-muted-foreground">
                                Nothing published in this language yet.
                            </li>
                        )}
                        {editor.history.map((version) => (
                            <li
                                key={version.version}
                                className="flex items-center justify-between gap-3 rounded-lg border p-3"
                            >
                                <div className="flex flex-col gap-0.5 text-sm">
                                    <span className="font-medium tabular-nums">
                                        Version {version.version}
                                        {version.isTemplate && (
                                            <Badge
                                                variant="secondary"
                                                className="ml-2"
                                            >
                                                Template
                                            </Badge>
                                        )}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {formatDate(version.publishedAt)}
                                        {version.publishedBy &&
                                            ` · ${version.publishedBy}`}
                                    </span>
                                </div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => {
                                        form.setData('body', version.body);
                                        setHistoryOpen(false);
                                    }}
                                >
                                    Use as starting point
                                </Button>
                            </li>
                        ))}
                    </ul>
                </SheetContent>
            </Sheet>

            <Sheet open={templateOpen} onOpenChange={setTemplateOpen}>
                <SheetContent className="data-[side=right]:sm:max-w-md">
                    <SheetHeader>
                        <SheetTitle>New template text</SheetTitle>
                        <SheetDescription>
                            Read only. Apply it from the banner to make it a new
                            version.
                        </SheetDescription>
                    </SheetHeader>
                    <div className="flex-1 overflow-y-auto px-4 pb-4">
                        <TermsPreview
                            body={editor.newerTemplateBody ?? ''}
                            values={editor.placeholderValues}
                        />
                    </div>
                </SheetContent>
            </Sheet>

            {document && (
                <DeleteDocumentDialog
                    document={document}
                    open={deleting}
                    onOpenChange={setDeleting}
                />
            )}

            <UnsavedChangesDialog
                guard={guard}
                name="the terms text"
                saving={form.processing}
                onSave={() => publish(guard.proceed)}
            />
        </div>
    );
}

export function RegistrationCardTermsForm({
    documents,
    editor,
}: {
    documents: Document[];
    editor: Editor | null;
}) {
    const [adding, setAdding] = useState(false);

    return (
        <div className="flex flex-col gap-4">
            <header className="flex flex-wrap items-end justify-between gap-3">
                <div className="space-y-1">
                    <h3 className="text-base font-medium">Terms</h3>
                    <p className="text-sm text-muted-foreground">
                        The terms a guest agrees to when signing. Each change is
                        a new version, so signed cards keep the text they
                        signed.
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {documents.length > 1 && editor && (
                        <EnumSelect
                            aria-label="Document"
                            className="w-48"
                            options={documents.map((document) => ({
                                value: String(document.id),
                                label: document.name,
                            }))}
                            value={String(editor.documentId)}
                            onValueChange={(value) =>
                                openEditor(Number(value), editor.locale)
                            }
                        />
                    )}
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => setAdding(true)}
                    >
                        <Plus data-icon="inline-start" />
                        Add document
                    </Button>
                    {editor && (
                        <EnumSelect
                            aria-label="Language"
                            className="w-56"
                            options={editor.localeOptions}
                            value={editor.locale}
                            onValueChange={(value) =>
                                openEditor(editor.documentId, value)
                            }
                        />
                    )}
                </div>
            </header>
            {editor ? (
                <TermsEditor
                    key={`${editor.documentId}-${editor.locale}-${editor.version}`}
                    editor={editor}
                    document={documents.find(
                        (document) => document.id === editor.documentId,
                    )}
                />
            ) : (
                <p className="text-sm text-muted-foreground">
                    This property has no terms document yet.
                </p>
            )}
            <AddDocumentDialog open={adding} onOpenChange={setAdding} />
        </div>
    );
}
