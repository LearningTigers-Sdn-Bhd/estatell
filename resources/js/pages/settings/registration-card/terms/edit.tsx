import { Head, useForm } from '@inertiajs/react';
import { History, Languages, MoreHorizontal, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/pms/page-header';
import { TermsDocumentEditor } from '@/components/pms/registration-card/terms-document-editor';
import { TabLinks, withTabCrumb } from '@/components/pms/tab-links';
import type { TabLink } from '@/components/pms/tab-links';
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
import { Spinner } from '@/components/ui/spinner';
import { edit as settingsEdit } from '@/routes/settings';
import { general, terms } from '@/routes/settings/registration-card';
import {
    destroy,
    edit,
} from '@/routes/settings/registration-card/terms/documents';

type Document = App.Data.Registration.TermsDocumentData;
type Editor = App.Data.Registration.TermsEditorData;

/** One tab per language. A language with no version yet says Draft. */
function languageTabs(document: Document, editor: Editor): TabLink[] {
    return editor.localeOptions.map((option) => ({
        value: option.value,
        label: option.published ? (
            option.label
        ) : (
            <span className="flex items-center gap-1.5">
                {option.label}
                <Badge variant="outline">Draft</Badge>
            </span>
        ),
        title: option.label,
        icon: Languages,
        href: edit.url(
            { termsDocument: document.id },
            { query: { locale: option.value } },
        ),
    }));
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
 * Settings › Registration card › Terms › {document}. One terms document, one tab per language (its own URL). The
 * editor sits in a fixed-height `Workspace` with the preview in the rail. More actions holds Version history and,
 * when allowed, Delete document.
 */
export default function TermsDocumentEdit({
    document,
    editor,
}: {
    document: Document;
    editor: Editor;
}) {
    const [historyOpen, setHistoryOpen] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const roomTypes =
        document.roomTypeCount === 1
            ? 'Used by 1 room type.'
            : `Used by ${document.roomTypeCount} room types.`;

    return (
        <>
            <Head title={document.name} />
            <div className="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto p-4 lg:overflow-hidden">
                <PageHeader
                    title={document.name}
                    description={
                        document.isDefault
                            ? `${roomTypes} Room types with no document of their own use it too.`
                            : roomTypes
                    }
                    back={{ href: terms.url(), label: 'Terms' }}
                    badge={
                        document.isDefault ? (
                            <Badge variant="secondary">Default</Badge>
                        ) : undefined
                    }
                    actions={
                        <DropdownMenu>
                            <DropdownMenuTrigger
                                render={<Button variant="outline" />}
                            >
                                <MoreHorizontal data-icon="inline-start" />
                                More actions
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem
                                    onClick={() => setHistoryOpen(true)}
                                >
                                    <History />
                                    Version history
                                </DropdownMenuItem>
                                {document.canDelete && (
                                    <DropdownMenuItem
                                        variant="destructive"
                                        onClick={() => setDeleting(true)}
                                    >
                                        <Trash2 />
                                        Delete document
                                    </DropdownMenuItem>
                                )}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    }
                />
                <TabLinks
                    activeTab={editor.locale}
                    tabs={languageTabs(document, editor)}
                    triggerClassName="md:min-w-0"
                    panelWidth="full"
                    className="flex flex-col gap-4 lg:min-h-0 lg:flex-1"
                    panelClassName="flex flex-col lg:min-h-0 lg:flex-1"
                >
                    <TermsDocumentEditor
                        key={`${editor.locale}-${editor.version}`}
                        editor={editor}
                        historyOpen={historyOpen}
                        onHistoryOpenChange={setHistoryOpen}
                    />
                </TabLinks>
            </div>
            {document.canDelete && (
                <DeleteDocumentDialog
                    document={document}
                    open={deleting}
                    onOpenChange={setDeleting}
                />
            )}
        </>
    );
}

TermsDocumentEdit.layout = (props: { document: Document; editor: Editor }) => ({
    variant: 'fixed',
    breadcrumbs: withTabCrumb(
        [
            { title: 'Settings', href: settingsEdit() },
            { title: 'Registration card', href: general() },
            { title: 'Terms', href: terms() },
            {
                title: props.document.name,
                href: edit({ termsDocument: props.document.id }),
            },
        ],
        languageTabs(props.document, props.editor),
        props.editor.locale,
    ),
});
