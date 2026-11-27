import { useForm } from '@inertiajs/react';
import { Eye, FileText, RefreshCw, Upload } from 'lucide-react';
import { useState } from 'react';
import { PanelHeading } from '@/components/pms/panel-heading';
import { formatTermsDate } from '@/components/pms/registration-card/registration-card-terms-list';
import {
    TermsPreview,
    countChecklistItems,
} from '@/components/pms/registration-card/terms-preview';
import { SaveBar } from '@/components/pms/save-bar';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Workspace } from '@/components/pms/workspace';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
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
import { apply } from '@/routes/settings/registration-card/terms/preset';
import { store as storeVersion } from '@/routes/settings/registration-card/terms/versions';

type Editor = App.Data.Registration.TermsEditorData;

const MAX_CHECKLIST_ITEMS = 10;

/** The version history of one language, in a side sheet. Picking a version loads its text into the editor. */
function TermsHistorySheet({
    editor,
    open,
    onOpenChange,
    onUse,
}: {
    editor: Editor;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onUse: (body: string) => void;
}) {
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent className="data-[side=right]:sm:max-w-md">
                <SheetHeader>
                    <SheetTitle>Version history</SheetTitle>
                    <SheetDescription>
                        Published versions never change. Use one as a starting
                        point, then publish it as a new version.
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
                                    {formatTermsDate(version.publishedAt)}
                                    {version.publishedBy &&
                                        ` · ${version.publishedBy}`}
                                </span>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => {
                                    onUse(version.body);
                                    onOpenChange(false);
                                }}
                            >
                                Use as starting point
                            </Button>
                        </li>
                    ))}
                </ul>
            </SheetContent>
        </Sheet>
    );
}

/**
 * The editor for one document in one language, with the live preview in the Workspace rail. Publish sits in the
 * floating Save bar, shown while the text has changed or the language is still a draft. The page remounts it (by key)
 * whenever the language or latest version changes, so its text always starts from the server copy.
 */
export function TermsDocumentEditor({
    editor,
    historyOpen,
    onHistoryOpenChange,
}: {
    editor: Editor;
    historyOpen: boolean;
    onHistoryOpenChange: (open: boolean) => void;
}) {
    const form = useForm({ locale: editor.locale, body: editor.body });
    const [templateOpen, setTemplateOpen] = useState(false);
    const applyForm = useForm({ locale: editor.locale });
    const dirty = form.data.body !== editor.body;
    const guard = useUnsavedChanges(dirty);
    const items = countChecklistItems(form.data.body);
    const nextVersion = (editor.version ?? 0) + 1;

    const publish = (onSuccess?: () => void) =>
        form.post(storeVersion.url({ termsDocument: editor.documentId }), {
            preserveScroll: true,
            onSuccess,
        });

    return (
        <Workspace
            asideLabel="Preview"
            asideWidth="wide"
            aside={
                <section className="flex flex-col gap-4">
                    <PanelHeading
                        icon={Eye}
                        title="Preview"
                        description="What the guest reads before signing, with sample details."
                    />
                    <div className="rounded-xl border p-4">
                        <TermsPreview
                            body={form.data.body}
                            values={editor.placeholderValues}
                        />
                    </div>
                </section>
            }
        >
            <form
                className="flex flex-col gap-5"
                onSubmit={(event) => {
                    event.preventDefault();
                    publish();
                }}
            >
                <PanelHeading
                    icon={FileText}
                    title="Terms text"
                    description={
                        editor.isDraft
                            ? `Not published in this language yet. It starts from the ${editor.presetLabel} template.`
                            : undefined
                    }
                />
                {!editor.isDraft && (
                    <p className="flex flex-wrap items-center gap-2 ps-2 text-sm text-muted-foreground">
                        <span className="font-medium text-foreground tabular-nums">
                            v{editor.version}
                        </span>
                        published
                        {editor.publishedAt && (
                            <time dateTime={editor.publishedAt}>
                                {formatTermsDate(editor.publishedAt)}
                            </time>
                        )}
                        {editor.publishedBy && <>by {editor.publishedBy}</>}
                        {editor.presetLabel && (
                            <Badge variant="secondary">
                                {editor.presetLabel} template
                            </Badge>
                        )}
                    </p>
                )}
                <Field>
                    <FieldLabel htmlFor="terms-body" className="sr-only">
                        Terms text
                    </FieldLabel>
                    <Textarea
                        id="terms-body"
                        className="field-sizing-fixed h-128 resize-none overflow-y-auto font-mono"
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
                                    <Eye data-icon="inline-start" />
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
                                    {applyForm.processing ? (
                                        <Spinner data-icon="inline-start" />
                                    ) : (
                                        <RefreshCw data-icon="inline-start" />
                                    )}
                                    Apply
                                </Button>
                            </span>
                        </AlertDescription>
                    </Alert>
                )}

                {(dirty || editor.isDraft) && (
                    <SaveBar
                        name="the terms text"
                        processing={form.processing}
                        onDiscard={() => {
                            form.setData('body', editor.body);
                            form.clearErrors();
                        }}
                        saveButton={
                            <Button
                                type="submit"
                                disabled={
                                    form.processing ||
                                    items > MAX_CHECKLIST_ITEMS
                                }
                                focusableWhenDisabled
                            >
                                {form.processing ? (
                                    <Spinner data-icon="inline-start" />
                                ) : (
                                    <Upload data-icon="inline-start" />
                                )}
                                Publish v{nextVersion}
                            </Button>
                        }
                    />
                )}
            </form>

            <TermsHistorySheet
                editor={editor}
                open={historyOpen}
                onOpenChange={onHistoryOpenChange}
                onUse={(body) => form.setData('body', body)}
            />

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

            <UnsavedChangesDialog
                guard={guard}
                name="the terms text"
                saving={form.processing}
                onSave={() => publish(guard.proceed)}
            />
        </Workspace>
    );
}
