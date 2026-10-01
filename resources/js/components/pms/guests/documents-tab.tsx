import { Link, useHttp } from '@inertiajs/react';
import { Eye, EyeOff, IdCard, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { StatusBadge } from '@/components/pms/status-badge';
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
import { destroy, edit, reveal, scan } from '@/routes/guests/documents';

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
    canManage,
    retentionDays,
}: {
    guest: Guest;
    documents: Document[];
    canManage: boolean;
    retentionDays: number | null;
}) {
    if (documents.length === 0) {
        return (
            <EmptyState
                icon={IdCard}
                title="No ID documents yet"
                description="Add a MyKad or passport with its number and scans."
            />
        );
    }

    return (
        <div className="flex flex-col gap-4">
            {retentionDays !== null && (
                <p className="text-sm text-muted-foreground">
                    Scans are deleted {retentionDays}{' '}
                    {retentionDays === 1 ? 'day' : 'days'} after they are
                    uploaded. The number stays.
                </p>
            )}
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
                                <div className="flex flex-wrap items-center gap-2">
                                    {document.hasFrontScan && (
                                        <ScanLink
                                            guest={guest}
                                            document={document}
                                            side="front"
                                        />
                                    )}
                                    {document.hasBackScan && (
                                        <ScanLink
                                            guest={guest}
                                            document={document}
                                            side="back"
                                        />
                                    )}
                                    {!document.hasFrontScan &&
                                        !document.hasBackScan && (
                                            <span className="text-sm text-muted-foreground">
                                                No scans
                                            </span>
                                        )}
                                    {canManage && (
                                        <span className="ms-auto flex gap-2">
                                            <Link
                                                href={edit.url({
                                                    guest: guest.id,
                                                    document: document.id,
                                                })}
                                                className={buttonVariants({
                                                    size: 'sm',
                                                    variant: 'outline',
                                                })}
                                            >
                                                <Pencil data-icon="inline-start" />
                                                Edit
                                            </Link>
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
        </div>
    );
}
