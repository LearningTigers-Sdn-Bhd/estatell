import { useHttp } from '@inertiajs/react';
import { Eye, EyeOff, IdCard, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { DocumentSheet } from '@/components/pms/guests/document-sheet';
import type { DocumentFormOptions } from '@/components/pms/guests/document-sheet';
import { StatusBadge } from '@/components/pms/status-badge';
import { useIsImpersonating } from '@/hooks/use-is-impersonating';
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
import { Button, buttonVariants } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { Form } from '@inertiajs/react';
import { destroy, reveal, scan } from '@/routes/guests/documents';

type Guest = App.Data.Guests.GuestData;
type Document = App.Data.Guests.GuestIdentityDocumentData;

/**
 * The number is masked. Reveal asks the server for the full number, and the server logs the request.
 */
function RevealNumber({
    guest,
    document,
}: {
    guest: Guest;
    document: Document;
}) {
    const [number, setNumber] = useState<string | null>(null);
    const impersonating = useIsImpersonating();
    const http = useHttp<Record<string, never>, { number: string }>(
        'post',
        reveal.url({ guest: guest.id, document: document.id }),
        {},
    );

    return (
        <span className="flex items-center gap-2">
            <span className="font-medium tabular-nums">
                {number ?? document.maskedNumber}
            </span>
            {number === null ? (
                impersonating ? null : (
                    <Button
                        type="button"
                        size="xs"
                        variant="outline"
                        disabled={http.processing}
                        focusableWhenDisabled
                        aria-label={`Reveal ${document.typeLabel} number`}
                        onClick={() =>
                            http
                                .submit()
                                .then((response) => setNumber(response.number))
                        }
                    >
                        {http.processing ? (
                            <Spinner data-icon="inline-start" />
                        ) : (
                            <Eye data-icon="inline-start" />
                        )}
                        Reveal
                    </Button>
                )
            ) : (
                <Button
                    type="button"
                    size="xs"
                    variant="outline"
                    onClick={() => setNumber(null)}
                >
                    <EyeOff data-icon="inline-start" />
                    Hide
                </Button>
            )}
        </span>
    );
}

function RemoveDocument({
    guest,
    document,
}: {
    guest: Guest;
    document: Document;
}) {
    const [open, setOpen] = useState(false);

    return (
        <AlertDialog open={open} onOpenChange={setOpen}>
            <AlertDialogTrigger render={<Button size="sm" variant="outline" />}>
                <Trash2 data-icon="inline-start" />
                Remove
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Remove {document.typeLabel} {document.maskedNumber}?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        The number and both scans are deleted. This cannot be
                        undone.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <Form
                    {...destroy.form({
                        guest: guest.id,
                        document: document.id,
                    })}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                >
                    {({ processing }) => (
                        <AlertDialogFooter>
                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={processing}
                                focusableWhenDisabled
                            >
                                {processing && (
                                    <Spinner data-icon="inline-start" />
                                )}
                                Remove document
                            </Button>
                        </AlertDialogFooter>
                    )}
                </Form>
            </AlertDialogContent>
        </AlertDialog>
    );
}

function ScanLink({
    guest,
    document,
    side,
}: {
    guest: Guest;
    document: Document;
    side: 'front' | 'back';
}) {
    return (
        <a
            href={scan.url({
                guest: guest.id,
                document: document.id,
                scan: side,
            })}
            target="_blank"
            rel="noopener noreferrer"
            className={buttonVariants({ size: 'sm', variant: 'outline' })}
        >
            {side === 'front' ? 'Front scan' : 'Back scan'}
        </a>
    );
}

/**
 * The guest's ID documents, with masked numbers, expiry badges and scan links. Staff with `guests.manage` can edit and remove.
 */
export function DocumentsTab({
    guest,
    documents,
    formOptions,
    retentionDays,
}: {
    guest: Guest;
    documents: Document[];
    /** Set when the user can add and edit documents, otherwise the tab is read-only. */
    formOptions: DocumentFormOptions | null;
    retentionDays: number | null;
}) {
    const [sheetOpen, setSheetOpen] = useState(false);
    const [editing, setEditing] = useState<Document | null>(null);
    const canManage = formOptions !== null;
    const impersonating = useIsImpersonating();

    const openSheet = (document: Document | null) => {
        setEditing(document);
        setSheetOpen(true);
    };

    const addButton = canManage ? (
        <Button onClick={() => openSheet(null)}>
            <Plus data-icon="inline-start" />
            Add document
        </Button>
    ) : undefined;

    const sheet = formOptions && (
        <DocumentSheet
            open={sheetOpen}
            onOpenChange={setSheetOpen}
            guest={guest}
            document={editing}
            options={formOptions}
        />
    );

    if (documents.length === 0) {
        return (
            <>
                <EmptyState
                    icon={IdCard}
                    title="No ID documents yet"
                    description="Add a MyKad or passport with its number and scans."
                    action={addButton}
                />
                {sheet}
            </>
        );
    }

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <h2 className="font-heading text-base font-medium">
                        ID documents
                    </h2>
                    {retentionDays !== null && (
                        <p className="text-sm text-muted-foreground">
                            Scans are deleted {retentionDays}{' '}
                            {retentionDays === 1 ? 'day' : 'days'} after they
                            are uploaded. The number stays.
                        </p>
                    )}
                </div>
                {addButton}
            </div>
            <ul className="flex flex-col gap-4">
                {documents.map((document) => (
                    <li key={document.id}>
                        <Card>
                            <CardContent className="flex flex-col gap-3">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="flex items-center gap-2">
                                        <span className="text-sm font-medium">
                                            {document.typeLabel}
                                        </span>
                                        {document.isExpired && (
                                            <StatusBadge
                                                status={{
                                                    value: 'expired',
                                                    label: 'Expired',
                                                    tone: 'warning',
                                                }}
                                            />
                                        )}
                                    </span>
                                    <RevealNumber
                                        guest={guest}
                                        document={document}
                                    />
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    {document.issuingCountryName ??
                                        'Country not set'}
                                    {' · '}
                                    {document.expiresOn ? (
                                        <>
                                            Expires{' '}
                                            <DateText
                                                date={document.expiresOn}
                                            />
                                        </>
                                    ) : (
                                        'No expiry date'
                                    )}
                                </p>

                                {(document.hasFrontScan ||
                                    document.hasBackScan) &&
                                    !impersonating && (
                                        <div className="flex flex-wrap items-center gap-4 rounded-md border border-border/80 bg-muted/30 p-2.5">
                                            {document.hasFrontScan && (
                                                <div className="flex items-center gap-3">
                                                    <a
                                                        href={scan.url({
                                                            guest: guest.id,
                                                            document:
                                                                document.id,
                                                            scan: 'front',
                                                        })}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="group relative block overflow-hidden rounded-md border border-border shadow-xs hover:ring-2 hover:ring-primary/40"
                                                        title="Click to view full image in new tab"
                                                    >
                                                        <img
                                                            src={scan.url({
                                                                guest: guest.id,
                                                                document:
                                                                    document.id,
                                                                scan: 'front',
                                                            })}
                                                            alt={`${document.typeLabel} front scan`}
                                                            className="h-24 w-20 object-cover transition group-hover:scale-105"
                                                            loading="lazy"
                                                        />
                                                        <span className="absolute inset-x-0 bottom-0 bg-black/60 py-0.5 text-center text-[10px] font-medium text-white">
                                                            Front
                                                        </span>
                                                    </a>
                                                    <div className="flex flex-col gap-1">
                                                        <span className="text-xs font-medium text-foreground">
                                                            Front Scan /
                                                            Portrait
                                                        </span>
                                                        <ScanLink
                                                            guest={guest}
                                                            document={document}
                                                            side="front"
                                                        />
                                                    </div>
                                                </div>
                                            )}
                                            {document.hasBackScan && (
                                                <div className="flex items-center gap-3 border-l border-border/60 pl-4">
                                                    <a
                                                        href={scan.url({
                                                            guest: guest.id,
                                                            document:
                                                                document.id,
                                                            scan: 'back',
                                                        })}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="group relative block overflow-hidden rounded-md border border-border shadow-xs hover:ring-2 hover:ring-primary/40"
                                                        title="Click to view full image in new tab"
                                                    >
                                                        <img
                                                            src={scan.url({
                                                                guest: guest.id,
                                                                document:
                                                                    document.id,
                                                                scan: 'back',
                                                            })}
                                                            alt={`${document.typeLabel} back scan`}
                                                            className="h-24 w-20 object-cover transition group-hover:scale-105"
                                                            loading="lazy"
                                                        />
                                                        <span className="absolute inset-x-0 bottom-0 bg-black/60 py-0.5 text-center text-[10px] font-medium text-white">
                                                            Back
                                                        </span>
                                                    </a>
                                                    <div className="flex flex-col gap-1">
                                                        <span className="text-xs font-medium text-foreground">
                                                            Back Scan
                                                        </span>
                                                        <ScanLink
                                                            guest={guest}
                                                            document={document}
                                                            side="back"
                                                        />
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    )}

                                <div className="flex flex-wrap items-center gap-2">
                                    {!document.hasFrontScan &&
                                        !document.hasBackScan && (
                                            <span className="text-sm text-muted-foreground">
                                                No scans attached
                                            </span>
                                        )}
                                    {canManage && (
                                        <span className="ms-auto flex gap-2">
                                            {!impersonating && (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() =>
                                                        openSheet(document)
                                                    }
                                                >
                                                    <Pencil data-icon="inline-start" />
                                                    Edit
                                                </Button>
                                            )}
                                            <RemoveDocument
                                                guest={guest}
                                                document={document}
                                            />
                                        </span>
                                    )}
                                </div>
                            </CardContent>
                        </Card>
                    </li>
                ))}
            </ul>
            {sheet}
        </div>
    );
}
