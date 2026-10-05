import { CountrySelect } from '@/components/pms/country-select';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';

export type IdentityInput = {
    type: string;
    number: string;
    issuing_country_code: string;
    expires_on: string;
};

/**
 * Pick one of the guest's ID documents, or type a different one. The staff page and the guest form share it.
 * Render it inside a FieldGroup grid.
 */
export function IdentityDocumentFields({
    documents,
    documentId,
    identity,
    onDocumentChange,
    onIdentityChange,
    identityTypes,
    countries,
    errors,
    label,
    word = (text) => text,
}: {
    documents: { id: number; label: string }[];
    documentId: string;
    identity: IdentityInput;
    onDocumentChange: (value: string) => void;
    onIdentityChange: (value: IdentityInput) => void;
    identityTypes: EnumOption[];
    countries: EnumOption[];
    errors: Record<string, string | undefined>;
    label: string;
    word?: (text: string) => string;
}) {
    return (
        <>
            {documents.length > 0 && (
                <Field data-invalid={!!errors.identity_document_id}>
                    <FieldLabel htmlFor="card-identity_document_id">
                        {label}
                    </FieldLabel>
                    <EnumSelect
                        id="card-identity_document_id"
                        name="identity_document_id"
                        options={[
                            ...documents.map((document) => ({
                                value: String(document.id),
                                label: document.label,
                            })),
                            {
                                value: '',
                                label: word('Enter a different document'),
                            },
                        ]}
                        value={documentId}
                        onValueChange={onDocumentChange}
                    />
                    <FieldError>{errors.identity_document_id}</FieldError>
                </Field>
            )}
            {documentId === '' && (
                <>
                    <Field data-invalid={!!errors['identity.type']}>
                        <FieldLabel htmlFor="card-identity-type">
                            {documents.length > 0
                                ? word('Document type')
                                : label}
                        </FieldLabel>
                        <EnumSelect
                            id="card-identity-type"
                            name="identity.type"
                            options={identityTypes.map((option) => ({
                                ...option,
                                label: word(option.label),
                            }))}
                            value={identity.type}
                            onValueChange={(value) =>
                                onIdentityChange({ ...identity, type: value })
                            }
                        />
                        <FieldError>{errors['identity.type']}</FieldError>
                    </Field>
                    <Field data-invalid={!!errors['identity.number']}>
                        <FieldLabel htmlFor="card-identity-number">
                            {word('Document number')}
                        </FieldLabel>
                        <Input
                            id="card-identity-number"
                            placeholder="900101-14-1234"
                            value={identity.number}
                            onChange={(event) =>
                                onIdentityChange({
                                    ...identity,
                                    number: event.target.value,
                                })
                            }
                            aria-invalid={!!errors['identity.number']}
                        />
                        <FieldError>{errors['identity.number']}</FieldError>
                    </Field>
                    <Field>
                        <FieldLabel htmlFor="card-identity-country">
                            {word('Issuing country')}
                        </FieldLabel>
                        <CountrySelect
                            id="card-identity-country"
                            value={identity.issuing_country_code}
                            onValueChange={(value) =>
                                onIdentityChange({
                                    ...identity,
                                    issuing_country_code: value,
                                })
                            }
                            options={countries}
                        />
                    </Field>
                </>
            )}
        </>
    );
}
