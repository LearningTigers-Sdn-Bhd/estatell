import { useForm, useHttp } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { CountrySelect } from '@/components/pms/country-select';
import { DatePicker } from '@/components/pms/date-picker';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { DuplicateWarning } from '@/components/pms/guests/duplicate-warning';
import { MyKadScanner } from '@/components/pms/mykad-scanner';
import { base64ToFile } from '@/hooks/use-mykad-reader';
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
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import type { GuestMatchCriteria } from '@/hooks/use-guest-matches';
import { reveal, scan, store, update } from '@/routes/guests/documents';

type Guest = App.Data.Guests.GuestData;
type Document = App.Data.Guests.GuestIdentityDocumentData;

export type DocumentFormOptions = {
    types: EnumOption[];
    countries: EnumOption[];
};

const NUMBER_PLACEHOLDERS: Record<string, string> = {
    mykad: '900101-14-1234',
    passport: 'A12345678',
    other: 'Document number',
};

const ACCEPT = 'image/jpeg,image/png,image/webp,application/pdf';

/**
 * The form inside the sheet. It mounts when the sheet opens, so it always starts from the saved values.
 * `number` is the full number, already fetched for an edit.
 */
function DocumentSheetForm({
    guest,
    document,
    number,
    options,
    onClose,
}: {
    guest: Guest;
    document: Document | null;
    number: string;
    options: DocumentFormOptions;
    onClose: () => void;
}) {
    const form = useForm({
        type: document?.type ?? 'mykad',
        number,
        issuing_country_code:
            document?.issuingCountryCode ?? guest.nationalityCode ?? 'MY',
        expires_on: document?.expiresOn ?? '',
        front_scan: null as File | null,
        back_scan: null as File | null,
        remove_front_scan: false,
        remove_back_scan: false,
    });
    const { data, errors } = form;
    const [checked, setChecked] = useState<GuestMatchCriteria>({});
    const [confirmingDiscard, setConfirmingDiscard] = useState(false);

    const requestClose = () =>
        form.isDirty ? setConfirmingDiscard(true) : onClose();

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        const visit = { forceFormData: true, onSuccess: onClose };

        if (document) {
            form.transform((fields) => ({ ...fields, _method: 'put' }));
            form.post(
                update.url({ guest: guest.id, document: document.id }),
                visit,
            );
        } else {
            form.post(store.url({ guest: guest.id }), visit);
        }
    };

    const scanField = (
        side: 'front' | 'back',
        label: string,
        existing: boolean,
    ) => {
        const field = `${side}_scan` as const;
        const removeField = `remove_${side}_scan` as const;

        return (
            <Field data-invalid={!!errors[field]}>
                <FieldLabel htmlFor={`document-${field}`}>{label}</FieldLabel>
                <Input
                    id={`document-${field}`}
                    type="file"
                    accept={ACCEPT}
                    onChange={(event) =>
                        form.setData(field, event.target.files?.[0] ?? null)
                    }
                    aria-invalid={!!errors[field]}
                />
                <FieldDescription>
                    JPEG, PNG, WebP or PDF, up to 10 MB.
                </FieldDescription>
                {data[field] instanceof File && (
                    <p className="text-xs font-medium text-success">
                        Photo file attached: {(data[field] as File).name}
                    </p>
                )}
                {document && existing && (
                    <div className="flex flex-wrap items-center gap-3 text-sm">
                        <a
                            href={scan.url({
                                guest: guest.id,
                                document: document.id,
                                scan: side,
                            })}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="underline underline-offset-4"
                        >
                            View current scan
                        </a>
                        <label className="flex items-center gap-2">
                            <Checkbox
                                checked={data[removeField]}
                                onCheckedChange={(checked) =>
                                    form.setData(removeField, checked === true)
                                }
                            />
                            Remove it
                        </label>
                    </div>
                )}
                <FieldError>{errors[field]}</FieldError>
            </Field>
        );
    };

    return (
        <>
            <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
                <FieldGroup className="flex-1 overflow-y-auto px-4">
                    <MyKadScanner
                        compact
                        onScan={(card) => {
                            form.setData('type', 'mykad');
                            form.setData(
                                'number',
                                card.formatted_ic || card.ic_number,
                            );
                            form.setData('issuing_country_code', 'MY');
                            if (card.photo_base64) {
                                const photoFile = base64ToFile(
                                    card.photo_base64,
                                    `mykad_${card.ic_number || 'portrait'}.jpg`,
                                );
                                if (photoFile) {
                                    form.setData('front_scan', photoFile);
                                }
                            }
                        }}
                    />
                    <Field data-invalid={!!errors.type}>
                        <FieldLabel htmlFor="document-type">
                            Document type
                        </FieldLabel>
                        <EnumSelect
                            id="document-type"
                            options={options.types}
                            value={data.type}
                            onValueChange={(value) =>
                                form.setData('type', value)
                            }
                            aria-invalid={!!errors.type}
                            className="w-full"
                        />
                        <FieldError>{errors.type}</FieldError>
                    </Field>
                    <Field data-invalid={!!errors.number}>
                        <FieldLabel htmlFor="document-number">
                            Number
                        </FieldLabel>
                        <Input
                            id="document-number"
                            value={data.number}
                            maxLength={40}
                            autoComplete="off"
                            autoFocus
                            placeholder={NUMBER_PLACEHOLDERS[data.type]}
                            onChange={(event) =>
                                form.setData('number', event.target.value)
                            }
                            onBlur={() =>
                                setChecked({
                                    id_numbers: [data.number],
                                    exclude_guest_id: guest.id,
                                })
                            }
                            aria-invalid={!!errors.number}
                        />
                        <FieldDescription>
                            Spaces and dashes are ignored.
                        </FieldDescription>
                        <FieldError>{errors.number}</FieldError>
                    </Field>
                    <DuplicateWarning criteria={checked} />
                    <Field data-invalid={!!errors.issuing_country_code}>
                        <FieldLabel htmlFor="document-issuing_country_code">
                            Issuing country
                        </FieldLabel>
                        <CountrySelect
                            id="document-issuing_country_code"
                            options={options.countries}
                            value={data.issuing_country_code}
                            onValueChange={(value) =>
                                form.setData('issuing_country_code', value)
                            }
                            invalid={!!errors.issuing_country_code}
                        />
                        <FieldError>{errors.issuing_country_code}</FieldError>
                    </Field>
                    <Field data-invalid={!!errors.expires_on}>
                        <FieldLabel htmlFor="document-expires_on">
                            Expiry date
                        </FieldLabel>
                        <DatePicker
                            id="document-expires_on"
                            placeholder="Pick expiry date"
                            value={data.expires_on}
                            onChange={(value) =>
                                form.setData('expires_on', value)
                            }
                            aria-invalid={!!errors.expires_on}
                        />
                        <FieldError>{errors.expires_on}</FieldError>
                    </Field>
                    {scanField('front', 'Front scan', !!document?.hasFrontScan)}
                    {scanField('back', 'Back scan', !!document?.hasBackScan)}
                </FieldGroup>
                <SheetFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={requestClose}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        disabled={form.processing}
                        focusableWhenDisabled
                    >
                        {form.processing && (
                            <Spinner data-icon="inline-start" />
                        )}
                        {document ? 'Save document' : 'Add document'}
                    </Button>
                </SheetFooter>
            </form>

            <AlertDialog
                open={confirmingDiscard}
                onOpenChange={setConfirmingDiscard}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Discard this document?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            The details you typed have not been saved.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Keep editing</AlertDialogCancel>
                        <Button variant="destructive" onClick={onClose}>
                            Discard
                        </Button>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

/**
 * Editing needs the full number. It comes from the logged `reveal` request when the sheet opens, never from
 * the tab's data, so opening the sheet is recorded like pressing Reveal.
 */
function EditDocumentSheetForm(props: {
    guest: Guest;
    document: Document;
    options: DocumentFormOptions;
    onClose: () => void;
}) {
    const [number, setNumber] = useState<string | null>(null);
    const [failed, setFailed] = useState(false);
    const requested = useRef(false);
    const http = useHttp<Record<string, never>, { number: string }>(
        'post',
        reveal.url({ guest: props.guest.id, document: props.document.id }),
        {},
    );

    useEffect(() => {
        if (requested.current) {
            return;
        }

        requested.current = true;
        http.submit()
            .then((response) => setNumber(response.number))
            .catch(() => setFailed(true));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    if (failed) {
        return (
            <div className="flex flex-col gap-3 px-4">
                <p className="text-sm text-destructive">
                    The document could not be loaded.
                </p>
                <Button
                    type="button"
                    variant="outline"
                    className="self-start"
                    onClick={props.onClose}
                >
                    Close
                </Button>
            </div>
        );
    }

    if (number === null) {
        return (
            <div className="flex flex-col gap-4 px-4" aria-busy="true">
                <Skeleton className="h-8 w-full" />
                <Skeleton className="h-8 w-full" />
                <Skeleton className="h-8 w-full" />
                <Skeleton className="h-8 w-full" />
            </div>
        );
    }

    return <DocumentSheetForm {...props} number={number} />;
}

/**
 * Adds or edits an ID document in a sheet. Scans are uploads. A new file replaces the old scan, and "Remove it"
 * deletes a scan without a replacement. Pass `document` to edit, or null to add.
 */
export function DocumentSheet({
    open,
    onOpenChange,
    guest,
    document,
    options,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    guest: Guest;
    document: Document | null;
    options: DocumentFormOptions;
}) {
    const onClose = () => onOpenChange(false);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent>
                <SheetHeader>
                    <SheetTitle>
                        {document
                            ? `Edit ${document.typeLabel}`
                            : 'Add ID document'}
                    </SheetTitle>
                    <SheetDescription>{guest.fullName}</SheetDescription>
                </SheetHeader>
                {document ? (
                    <EditDocumentSheetForm
                        key={document.id}
                        guest={guest}
                        document={document}
                        options={options}
                        onClose={onClose}
                    />
                ) : (
                    <DocumentSheetForm
                        guest={guest}
                        document={null}
                        number=""
                        options={options}
                        onClose={onClose}
                    />
                )}
            </SheetContent>
        </Sheet>
    );
}
