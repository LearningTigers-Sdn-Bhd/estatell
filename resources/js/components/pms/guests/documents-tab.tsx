import { Form, useHttp } from '@inertiajs/react';
import { Ellipsis, IdCard, Pencil, Plus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { DocumentSheet } from '@/components/pms/guests/document-sheet';
import type { DocumentFormOptions } from '@/components/pms/guests/document-sheet';
import { PanelHeading } from '@/components/pms/panel-heading';
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
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { destroy, reveal, scan } from '@/routes/guests/documents';

type Guest = Pick<
    App.Data.Guests.GuestData,
    'id' | 'fullName' | 'nationalityCode'
>;
type Document = App.Data.Guests.GuestIdentityDocumentData;

/**
 * The full number, loaded through the logged `reveal` request as on the Details form: page props never carry it.
 * The masked number shows while it loads, if it fails, and while impersonating.
 */
function DocumentNumber({
    guest,
    document,
}: {
    guest: Guest;
    document: Document;
}) {
    const [number, setNumber] = useState<string | null>(null);
    const impersonating = useIsImpersonating();
    const http = useHttp<Record<string, never>, { number: string }>({});

    useEffect(() => {
        if (impersonating) {
            return;
        }
        let active = true;
        http.post(reveal.url({ guest: guest.id, document: document.id }))
            .then((response) => {
                if (active) {
                    setNumber(response.number);
                }
            })
            .catch(() => undefined);
        return () => {
            active = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [guest.id, document.id, document.maskedNumber, impersonating]);

    return (
        <span className="inline-flex items-center gap-2 font-medium tabular-nums">
            {number ?? document.maskedNumber}
            {number === null && http.processing && (
                <Spinner className="size-3.5 text-muted-foreground" />
            )}
        </span>
    );
}

/** A small scan thumbnail that opens the full image in a new tab. Opening it is logged by the scan route. */
function ScanThumbnail({
    guest,
    document,
    side,
}: {
    guest: Guest;
    document: Document;
    side: 'front' | 'back';
}) {
    const url = scan.url({
        guest: guest.id,
        document: document.id,
        scan: side,
    });
    const label = side === 'front' ? 'Front' : 'Back';

    return (
        <a
            href={url}
            target="_blank"
            rel="noopener noreferrer"
            className="group relative block overflow-hidden rounded-md border shadow-xs hover:ring-2 hover:ring-primary/40"
            aria-label={`Open the ${label.toLowerCase()} scan of ${document.typeLabel}`}
        >
            <img
                src={url}
                alt={`${document.typeLabel} ${label.toLowerCase()} scan`}
                className="h-12 w-10 object-cover transition group-hover:scale-105"
                loading="lazy"
            />
            <span className="absolute inset-x-0 bottom-0 bg-foreground/60 text-center text-xs text-background">
                {label}
            </span>
        </a>
    );
}

/** The ⋯ menu of a row: Edit opens the sheet, Remove asks first. */
function DocumentActions({
    guest,
    document,
    onEdit,
}: {
    guest: Guest;
    document: Document;
    onEdit: (() => void) | null;
}) {
    const [removing, setRemoving] = useState(false);

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger
                    render={<Button variant="ghost" size="icon-sm" />}
                    aria-label={`Actions for ${document.typeLabel}`}
                >
                    <Ellipsis />
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-auto min-w-48">
                    {onEdit && (
                        <>
                            <DropdownMenuItem onClick={onEdit}>
                                <Pencil />
                                Edit
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                        </>
                    )}
                    <DropdownMenuItem
                        variant="destructive"
                        onClick={() => setRemoving(true)}
                    >
                        <Trash2 />
                        Remove…
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            <AlertDialog open={removing} onOpenChange={setRemoving}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Remove {document.typeLabel} {document.maskedNumber}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            The number and both scans are deleted. This cannot
                            be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <Form
                        {...destroy.form({
                            guest: guest.id,
                            document: document.id,
                        })}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setRemoving(false)}
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
        </>
    );
}

/**
 * The guest's ID documents in a table: type, full number, country, expiry and scans. Staff with `guests.manage` edit
 * and remove from the row menu.
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
            Add passport / IC
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

    const heading = (
        <PanelHeading
            icon={IdCard}
            title="ID documents"
            description={
                retentionDays !== null
                    ? `Scans are deleted ${retentionDays} ${retentionDays === 1 ? 'day' : 'days'} after they are uploaded. The number stays.`
                    : 'Passports and ICs of the guest.'
            }
            actions={documents.length > 0 ? addButton : undefined}
        />
    );

    if (documents.length === 0) {
        return (
            <div className="flex flex-col gap-4">
                {heading}
                <EmptyState
                    icon={IdCard}
                    title="No ID documents yet"
                    description="Type a passport or IC number manually. A MyKad reader and scans are optional."
                    action={addButton}
                />
                {sheet}
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-4">
            {heading}
            <div className="overflow-auto rounded-xl border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Type</TableHead>
                            <TableHead>Number</TableHead>
                            <TableHead>Country</TableHead>
                            <TableHead>Expires</TableHead>
                            <TableHead>Scans</TableHead>
                            {canManage && (
                                <TableHead>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            )}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {documents.map((document) => (
                            <TableRow key={document.id}>
                                <TableCell>
                                    <span className="flex items-center gap-2">
                                        <span className="font-medium">
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
                                </TableCell>
                                <TableCell>
                                    <DocumentNumber
                                        guest={guest}
                                        document={document}
                                    />
                                </TableCell>
                                <TableCell>
                                    {document.issuingCountryName ?? '—'}
                                </TableCell>
                                <TableCell className="tabular-nums">
                                    {document.expiresOn ? (
                                        <DateText date={document.expiresOn} />
                                    ) : (
                                        '—'
                                    )}
                                </TableCell>
                                <TableCell>
                                    {!document.hasFrontScan &&
                                    !document.hasBackScan ? (
                                        <span className="text-muted-foreground">
                                            No scans
                                        </span>
                                    ) : impersonating ? (
                                        <span className="text-muted-foreground">
                                            Hidden
                                        </span>
                                    ) : (
                                        <span className="flex gap-2">
                                            {document.hasFrontScan && (
                                                <ScanThumbnail
                                                    guest={guest}
                                                    document={document}
                                                    side="front"
                                                />
                                            )}
                                            {document.hasBackScan && (
                                                <ScanThumbnail
                                                    guest={guest}
                                                    document={document}
                                                    side="back"
                                                />
                                            )}
                                        </span>
                                    )}
                                </TableCell>
                                {canManage && (
                                    <TableCell className="text-right">
                                        <DocumentActions
                                            guest={guest}
                                            document={document}
                                            onEdit={
                                                impersonating
                                                    ? null
                                                    : () => openSheet(document)
                                            }
                                        />
                                    </TableCell>
                                )}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
            {sheet}
        </div>
    );
}
