import { Link, useHttp } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import { EnumSelect, type EnumOption } from '@/components/pms/enum-select';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupButton,
    InputGroupInput,
} from '@/components/ui/input-group';
import { Spinner } from '@/components/ui/spinner';
import { useIsImpersonating } from '@/hooks/use-is-impersonating';
import { index, reveal } from '@/routes/guests/documents';

type Document = App.Data.Guests.GuestIdentityDocumentData;

export type GuestIdentityValue = {
    identity_document_id: string;
    identity_type: string;
    identity_number: string;
    identity_country_code: string;
};

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
}: {
    guestId: number | null;
    document?: Document | null;
    values: GuestIdentityValue;
    types: EnumOption[];
    errors?: Record<string, string | undefined>;
    disabled?: boolean;
    onChange: (patch: Partial<GuestIdentityValue>) => void;
}) {
    const id = useId();
    const numberInput = useRef<HTMLInputElement>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const impersonating = useIsImpersonating();
    const locked = disabled || impersonating;
    const editing =
        document === null ||
        values.identity_document_id !== '' ||
        values.identity_number !== '';
    const http = useHttp<Record<string, never>, { number: string }>(
        'post',
        document && guestId !== null
            ? reveal.url({ guest: guestId, document: document.id })
            : '',
        {},
    );
    const error =
        errors.identity_number ??
        errors.identity_type ??
        errors.identity_document_id ??
        errors.identity_country_code ??
        loadError;
    const numberLabel =
        values.identity_type === 'passport'
            ? 'passport number'
            : values.identity_type === 'mykad'
              ? 'IC number'
              : 'document number';

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
            <InputGroup>
                <InputGroupAddon className="border-r p-0">
                    <EnumSelect
                        id={`${id}-type`}
                        name="identity_type"
                        aria-label="Identity document type"
                        options={types}
                        value={values.identity_type}
                        onValueChange={(value) =>
                            onChange({ identity_type: value })
                        }
                        disabled={locked || !editing}
                        className="h-7 w-28 rounded-none border-0 shadow-none"
                    />
                </InputGroupAddon>
                <InputGroupInput
                    ref={numberInput}
                    id={`${id}-number`}
                    name="identity_number"
                    value={
                        editing
                            ? values.identity_number
                            : (document?.maskedNumber ?? '')
                    }
                    onChange={(event) =>
                        onChange({ identity_number: event.target.value })
                    }
                    readOnly={locked || !editing}
                    placeholder={
                        locked ? 'Not recorded' : `Enter ${numberLabel}`
                    }
                    autoComplete="off"
                    maxLength={40}
                    aria-invalid={!!error}
                />
                {document && !locked && !editing && (
                    <InputGroupAddon align="inline-end">
                        <InputGroupButton
                            aria-label={`Edit ${numberLabel}`}
                            disabled={http.processing}
                            focusableWhenDisabled
                            onClick={() => {
                                setLoadError(null);
                                http.submit()
                                    .then((response) => {
                                        onChange({
                                            identity_document_id: String(
                                                document.id,
                                            ),
                                            identity_type: document.type,
                                            identity_number: response.number,
                                            identity_country_code:
                                                document.issuingCountryCode ??
                                                '',
                                        });
                                        requestAnimationFrame(() =>
                                            numberInput.current?.focus(),
                                        );
                                    })
                                    .catch(() =>
                                        setLoadError(
                                            'The ID number could not be loaded. Try again.',
                                        ),
                                    );
                            }}
                        >
                            {http.processing ? <Spinner /> : <Pencil />}
                        </InputGroupButton>
                    </InputGroupAddon>
                )}
            </InputGroup>
            <FieldError>{error}</FieldError>
        </Field>
    );
}
