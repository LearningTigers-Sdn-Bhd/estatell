import { Link, useForm } from '@inertiajs/react';
import { ChevronRight, FileText, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { PanelHeading } from '@/components/pms/panel-heading';
import { Workspace } from '@/components/pms/workspace';
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
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    edit,
    store,
} from '@/routes/settings/registration-card/terms/documents';

type Document = App.Data.Registration.TermsDocumentData;

export const formatTermsDate = (iso: string) =>
    new Date(iso).toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });

/** Name and template. Create opens the new document's page. */
function NewDocumentDialog({
    open,
    onOpenChange,
    presets,
    defaultPreset,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    presets: EnumOption[];
    defaultPreset: string;
}) {
    const form = useForm({ name: '', preset: defaultPreset });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(store.url(), {
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
                        <DialogTitle>New terms document</DialogTitle>
                        <DialogDescription>
                            It starts as a copy of the template you pick. Assign
                            it to room types on the room type form.
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
                    <Field>
                        <FieldLabel htmlFor="terms-document-preset">
                            Start from
                        </FieldLabel>
                        <EnumSelect
                            id="terms-document-preset"
                            name="preset"
                            className="w-full"
                            options={presets}
                            value={form.data.preset}
                            onValueChange={(value) =>
                                form.setData('preset', value)
                            }
                            aria-invalid={!!form.errors.preset}
                        />
                        <FieldDescription>
                            Templates are a starting point, not legal advice.
                            You can edit the text after.
                        </FieldDescription>
                        <FieldError>{form.errors.preset}</FieldError>
                    </Field>
                    <DialogFooter>
                        <Button
                            type="submit"
                            disabled={form.processing}
                            focusableWhenDisabled
                        >
                            {form.processing && <Spinner />}
                            Create document
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function TermsDocumentTable({ documents }: { documents: Document[] }) {
    return (
        <div className="overflow-auto rounded-xl border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Name</TableHead>
                        <TableHead>Languages</TableHead>
                        <TableHead className="text-right">Room types</TableHead>
                        <TableHead>Last published</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {documents.map((document) => (
                        <TableRow key={document.id} className="relative">
                            <TableCell>
                                <span className="flex items-center gap-2">
                                    <Link
                                        href={edit({
                                            termsDocument: document.id,
                                        })}
                                        className="rounded-sm font-medium outline-none after:absolute after:inset-0 focus-visible:ring-2 focus-visible:ring-ring"
                                    >
                                        {document.name}
                                    </Link>
                                    {document.isDefault && (
                                        <Badge variant="secondary">
                                            Default
                                        </Badge>
                                    )}
                                </span>
                            </TableCell>
                            <TableCell>
                                <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
                                    {document.languages.map((language) => (
                                        <span
                                            key={language.locale}
                                            className="flex items-center gap-1.5"
                                        >
                                            {language.label}
                                            {language.version === null ? (
                                                <Badge variant="outline">
                                                    Draft
                                                </Badge>
                                            ) : (
                                                <span className="text-muted-foreground tabular-nums">
                                                    v{language.version}
                                                </span>
                                            )}
                                        </span>
                                    ))}
                                </span>
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {document.roomTypeCount}
                            </TableCell>
                            <TableCell>
                                <span className="flex items-center justify-between gap-2">
                                    {document.lastPublishedAt ? (
                                        <time
                                            dateTime={document.lastPublishedAt}
                                        >
                                            {formatTermsDate(
                                                document.lastPublishedAt,
                                            )}
                                        </time>
                                    ) : (
                                        <span className="text-muted-foreground">
                                            Not published
                                        </span>
                                    )}
                                    <ChevronRight
                                        aria-hidden
                                        className="size-4 shrink-0 text-muted-foreground"
                                    />
                                </span>
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}

/**
 * Registration card › Terms: the terms documents in one table, like Room types. A row opens the document's own
 * editor page. Search filters by name in the browser.
 */
export function RegistrationCardTermsList({
    documents,
    presets,
    defaultPreset,
}: {
    documents: Document[];
    presets: EnumOption[];
    defaultPreset: string;
}) {
    const [creating, setCreating] = useState(false);
    const [query, setQuery] = useState('');
    const filtered = useMemo(() => {
        const normalized = query.trim().toLocaleLowerCase();

        if (!normalized) {
            return documents;
        }

        return documents.filter((document) =>
            document.name.toLocaleLowerCase().includes(normalized),
        );
    }, [documents, query]);

    const newButton = (
        <Button onClick={() => setCreating(true)}>
            <Plus data-icon="inline-start" />
            New document
        </Button>
    );

    return (
        <Workspace hideAside>
            <section className="flex flex-col gap-5">
                <PanelHeading
                    icon={FileText}
                    title="Terms"
                    description="What guests agree to when signing. Each change is a new version, so signed cards keep the text they signed."
                    actions={documents.length > 0 ? newButton : undefined}
                />
                {documents.length === 0 ? (
                    <EmptyState
                        icon={FileText}
                        title="No terms documents yet"
                        description="Add one so guests have terms to agree to when signing."
                        action={newButton}
                    />
                ) : (
                    <>
                        <Field>
                            <FieldLabel htmlFor="terms-document-search">
                                Search documents
                            </FieldLabel>
                            <Input
                                id="terms-document-search"
                                type="search"
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                                placeholder="Search by name"
                            />
                        </Field>
                        {filtered.length > 0 ? (
                            <TermsDocumentTable documents={filtered} />
                        ) : (
                            <EmptyState
                                title="No matching documents"
                                description="Change the search or add a new document."
                            />
                        )}
                    </>
                )}
            </section>
            <NewDocumentDialog
                open={creating}
                onOpenChange={setCreating}
                presets={presets}
                defaultPreset={defaultPreset}
            />
        </Workspace>
    );
}
