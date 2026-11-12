import { Link, useForm, useHttp } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import {
    AlertDialog,
    AlertDialogContent,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogCancel,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { EnumSelect, type EnumOption } from '@/components/pms/enum-select';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupButton,
    InputGroupInput,
} from '@/components/ui/input-group';
import { useRetainedValue } from '@/components/pms/use-retained-value';
import { Spinner } from '@/components/ui/spinner';
import { useIsImpersonating } from '@/hooks/use-is-impersonating';
import { destroy, index, reveal } from '@/routes/guests/documents';

type Document = App.Data.Guests.GuestIdentityDocumentData;

export type GuestIdentityValue = {
    identity_document_id: string;
    identity_type: string;
    identity_number: string;
    identity_country_code: string;
};

/**
 * The ID type select inside the IC / Passport input group: no own border, background or focus ring, so it reads as
 * part of the group. The group shows the focus ring instead, the same as when its input has focus.
 */
export const ID_TYPE_SELECT =
    'h-7 w-28 rounded-none border-0 bg-transparent shadow-none focus-visible:ring-0 dark:bg-transparent dark:hover:bg-transparent';

/** The input group around the ID type select and the number: it rings while the select has keyboard focus. */
export const ID_GROUP =
    'has-[[data-slot=select-trigger]:focus-visible]:border-ring has-[[data-slot=select-trigger]:focus-visible]:ring-3 has-[[data-slot=select-trigger]:focus-visible]:ring-ring/50';

export function emptyGuestIdentity(
    nationality: string | null,
    document?: Document | null,
): GuestIdentityValue {
    return {
        identity_document_id: '',
        identity_type:
            document?.type ??
            (nationality && nationality !== 'MY' ? 'passport' : 'mykad'),
        identity_number: '',
        identity_country_code:
            document?.issuingCountryCode ?? nationality ?? '',
    };
}

/** The parent guest form owns this field and saves it with the profile. */
export function GuestIdentityFields({
    guestId,
    document = null,
    values,
    types,
    errors = {},
    disabled = false,
    onChange,
    onRemoved,
    savedVersion = 0,
}: {
    guestId: number | null;
    document?: Document | null;
    values: GuestIdentityValue;
    types: EnumOption[];
    errors?: Record<string, string | undefined>;
    disabled?: boolean;
    onChange: (patch: Partial<GuestIdentityValue>) => void;
    onRemoved?: () => void;
    savedVersion?: number;
}) {
    const id = useId();
    const [loadError, setLoadError] = useState<string | null>(null);
    const [loaded, setLoaded] = useState<{
        document: Document;
        number: string;
        savedVersion: number;
    } | null>(null);
    const [confirmingRemove, setConfirmingRemove] = useState(false);
    const removingDocument = useRetainedValue(
        confirmingRemove ? document : null,
    );
    const removal = useForm({});
    const impersonating = useIsImpersonating();
    const locked = disabled || impersonating || removal.processing;
    const changed =
        values.identity_document_id !== '' || values.identity_number !== '';
    const http = useHttp<Record<string, never>, { number: string }>({});
    const ready =
        changed ||
        document === null ||
        (loaded?.document === document && loaded.savedVersion === savedVersion);

    useEffect(() => {
        if (document === null || guestId === null || impersonating) {
            return;
        }
        let active = true;
        http.post(reveal.url({ guest: guestId, document: document.id }))
            .then((response) => {
                if (active) {
                    setLoaded({
                        document,
                        number: response.number,
                        savedVersion,
                    });
                    setLoadError(null);
                }
            })
            .catch(() => {
                if (active) {
                    setLoadError(
                        'The ID number could not be loaded. Refresh to try again.',
                    );
                }
            });
        return () => {
            active = false;
        };
        // The request follows the current document, not the hook's render state.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [document, guestId, impersonating, savedVersion]);

    const clearedSaved =
        document !== null &&
        values.identity_document_id !== '' &&
        values.identity_number.trim() === '';
    const error =
        errors.identity_number ??
        errors.identity_type ??
        errors.identity_document_id ??
        errors.identity_country_code ??
        loadError ??
        (clearedSaved
            ? "The ID number can't be left blank. To remove this ID, use the bin icon."
            : undefined);
    const visibleType =
        !changed && document ? document.type : values.identity_type;
    const numberLabel =
        visibleType === 'passport'
            ? 'passport number'
            : visibleType === 'mykad'
              ? 'IC number'
              : 'document number';

    const visibleNumber =
        changed || document === null
            ? values.identity_number
            : impersonating
              ? document.maskedNumber
              : ready
                ? (loaded?.number ?? '')
                : '';
    const change = (patch: Partial<GuestIdentityValue>) =>
        onChange({
            ...(!changed && document
                ? {
                      identity_document_id: String(document.id),
                      identity_type: document.type,
                      identity_number: visibleNumber,
                      identity_country_code: document.issuingCountryCode ?? '',
                  }
                : {}),
            ...patch,
        });

    return (
        <Field data-invalid={!!error}>
            <div className="flex items-center justify-between gap-3">
                <FieldLabel htmlFor={`${id}-number`}>
                    IC / Passport number
                </FieldLabel>
                {guestId !== null && (
                    <Link
                        href={index.url({ guest: guestId })}
                        className="text-xs text-muted-foreground underline-offset-4 hover:underline"
                    >
                        Documents
                    </Link>
                )}
            </div>
            <InputGroup className={ID_GROUP}>
                <InputGroupAddon className="border-r p-0">
                    <EnumSelect
                        id={`${id}-type`}
                        name="identity_type"
                        aria-label="Identity document type"
                        options={types}
                        value={visibleType}
                        onValueChange={(value) =>
                            change({ identity_type: value })
                        }
                        disabled={locked || !ready}
                        className={ID_TYPE_SELECT}
                    />
                </InputGroupAddon>
                <InputGroupInput
                    id={`${id}-number`}
                    name="identity_number"
                    value={visibleNumber}
                    onChange={(event) =>
                        change({ identity_number: event.target.value })
                    }
                    readOnly={locked || !ready}
                    placeholder={
                        !ready
                            ? 'Loading ID number…'
                            : locked
                              ? 'Not recorded'
                              : `Enter ${numberLabel}`
                    }
                    autoComplete="off"
                    maxLength={40}
                    aria-invalid={!!error}
                />
                {document && guestId !== null && !locked && (
                    <InputGroupAddon align="inline-end">
                        <InputGroupButton
                            aria-label={`Remove ${numberLabel}`}
                            onClick={() => setConfirmingRemove(true)}
                        >
                            <Trash2 />
                        </InputGroupButton>
                    </InputGroupAddon>
                )}
            </InputGroup>
            <FieldError>{error}</FieldError>
            {removingDocument && guestId !== null && (
                <AlertDialog
                    open={confirmingRemove}
                    onOpenChange={setConfirmingRemove}
                >
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>
                                Remove {removingDocument.typeLabel}{' '}
                                {removingDocument.maskedNumber}?
                            </AlertDialogTitle>
                            <AlertDialogDescription>
                                This deletes this ID number and any attached
                                scans from the guest profile. It cannot be
                                undone. Other documents are kept.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel disabled={removal.processing}>
                                Keep ID
                            </AlertDialogCancel>
                            <Button
                                type="button"
                                variant="destructive"
                                disabled={removal.processing}
                                focusableWhenDisabled
                                onClick={() =>
                                    removal.delete(
                                        destroy.url({
                                            guest: guestId,
                                            document: removingDocument.id,
                                        }),
                                        {
                                            preserveScroll: true,
                                            onSuccess: () => {
                                                setConfirmingRemove(false);
                                                setLoadError(null);
                                                onChange(
                                                    emptyGuestIdentity(
                                                        values.identity_country_code,
                                                    ),
                                                );
                                                onRemoved?.();
                                            },
                                            onError: () => {
                                                setConfirmingRemove(false);
                                                setLoadError(
                                                    'The ID could not be removed. Try again.',
                                                );
                                            },
                                        },
                                    )
                                }
                            >
                                {removal.processing && <Spinner />}
                                Remove ID
                            </Button>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            )}
        </Field>
    );
}
