import { CountrySelect } from '@/components/pms/country-select';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { CardSection } from '@/components/pms/registration-card/card-section';
import { CardTextField } from '@/components/pms/registration-card/card-text-field';
import { IdentityDocumentFields } from '@/components/pms/registration-card/identity-document-fields';
import type { CardSectionProps } from '@/components/pms/registration-card/use-card-form';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Checkbox } from '@/components/ui/checkbox';

type Options = {
    genders: EnumOption[];
    identityTypes: EnumOption[];
    countries: EnumOption[];
};

/** Name, nationality, date of birth and gender. */
export function PersonalSection({
    form,
    word,
    mark,
    has,
    genders,
    countries,
}: CardSectionProps & Pick<Options, 'genders' | 'countries'>) {
    const errors = form.errors as Record<string, string>;

    return (
        <CardSection title={word('Personal')}>
            <FieldGroup className="grid gap-4 sm:grid-cols-2">
                <CardTextField
                    form={form}
                    name="full_name"
                    label={mark('full_name', 'Full name')}
                    placeholder="Aisyah Rahman"
                />
                {has('nationality') && (
                    <Field data-invalid={!!errors.nationality_code}>
                        <FieldLabel htmlFor="card-nationality_code">
                            {mark('nationality', 'Nationality')}
                        </FieldLabel>
                        <CountrySelect
                            id="card-nationality_code"
                            value={form.data.nationality_code}
                            onValueChange={(value) =>
                                form.setData('nationality_code', value)
                            }
                            options={countries}
                            invalid={!!errors.nationality_code}
                        />
                        <FieldError>{errors.nationality_code}</FieldError>
                    </Field>
                )}
                {has('date_of_birth') && (
                    <CardTextField
                        form={form}
                        name="date_of_birth"
                        label={mark('date_of_birth', 'Date of birth')}
                        type="date"
                    />
                )}
                {has('gender') && (
                    <Field data-invalid={!!errors.gender}>
                        <FieldLabel htmlFor="card-gender">
                            {mark('gender', 'Gender')}
                        </FieldLabel>
                        <EnumSelect
                            id="card-gender"
                            options={genders.map((option) => ({
                                ...option,
                                label: word(option.label),
                            }))}
                            allLabel={word('Select')}
                            value={form.data.gender}
                            onValueChange={(value) =>
                                form.setData('gender', value)
                            }
                            aria-invalid={!!errors.gender}
                        />
                        <FieldError>{errors.gender}</FieldError>
                    </Field>
                )}
            </FieldGroup>
        </CardSection>
    );
}

/** Phone, email, vehicle plate and the address. Hidden when the Layout tab hides all of them. */
export function ContactSection({
    form,
    word,
    mark,
    has,
    countries,
}: CardSectionProps & Pick<Options, 'countries'>) {
    const errors = form.errors as Record<string, string>;

    if (
        !has('phone') &&
        !has('email') &&
        !has('vehicle_plate') &&
        !has('address')
    ) {
        return null;
    }

    return (
        <CardSection title={word('Contact')}>
            <FieldGroup className="grid gap-4 sm:grid-cols-2">
                {has('phone') && (
                    <CardTextField
                        form={form}
                        name="phone"
                        label={mark('phone', 'Phone')}
                        type="tel"
                        placeholder="+60 12 345 6789"
                    />
                )}
                {has('email') && (
                    <CardTextField
                        form={form}
                        name="email"
                        label={mark('email', 'Email')}
                        type="email"
                        placeholder="name@example.com"
                    />
                )}
                {has('vehicle_plate') && (
                    <CardTextField
                        form={form}
                        name="vehicle_plate"
                        label={mark('vehicle_plate', 'Vehicle plate')}
                        placeholder="WXY 1234"
                    />
                )}
            </FieldGroup>
            {has('address') && (
                <FieldGroup className="grid gap-4 sm:grid-cols-2">
                    <CardTextField
                        form={form}
                        name="address_line1"
                        label={mark('address', 'Address line 1')}
                        placeholder={word('Street and number')}
                    />
                    <CardTextField
                        form={form}
                        name="address_line2"
                        label={word('Address line 2')}
                        placeholder={word('Unit, floor or building')}
                    />
                    <CardTextField
                        form={form}
                        name="city"
                        label={word('City')}
                        placeholder="Kuala Lumpur"
                    />
                    <CardTextField
                        form={form}
                        name="state"
                        label={word('State')}
                        placeholder="Selangor"
                    />
                    <CardTextField
                        form={form}
                        name="postal_code"
                        label={word('Postal code')}
                        placeholder="50450"
                    />
                    <Field data-invalid={!!errors.country_code}>
                        <FieldLabel htmlFor="card-country_code">
                            {word('Country')}
                        </FieldLabel>
                        <CountrySelect
                            id="card-country_code"
                            value={form.data.country_code}
                            onValueChange={(value) =>
                                form.setData('country_code', value)
                            }
                            options={countries}
                            invalid={!!errors.country_code}
                        />
                        <FieldError>{errors.country_code}</FieldError>
                    </Field>
                </FieldGroup>
            )}
        </CardSection>
    );
}

/** The ID document: the one staff picked (read-only), or a pick or new entry. */
export function IdentitySection({
    card,
    form,
    word,
    mark,
    has,
    identityTypes,
    countries,
}: CardSectionProps & Pick<Options, 'identityTypes' | 'countries'>) {
    if (!has('identity_document')) {
        return null;
    }

    const pickedDocument = card.documents.find(
        (document) => document.id === card.cardDocumentId,
    );

    return (
        <CardSection title={word('Identity')}>
            <FieldGroup className="grid gap-4 sm:grid-cols-2">
                {pickedDocument ? (
                    <Field>
                        <FieldLabel>
                            {mark('identity_document', 'ID document')}
                        </FieldLabel>
                        <p className="text-sm font-medium tabular-nums">
                            {pickedDocument.label}
                        </p>
                    </Field>
                ) : (
                    <IdentityDocumentFields
                        documents={card.documents}
                        documentId={form.data.identity_document_id}
                        identity={form.data.identity}
                        onDocumentChange={(value) =>
                            form.setData('identity_document_id', value)
                        }
                        onIdentityChange={(value) =>
                            form.setData('identity', value)
                        }
                        identityTypes={identityTypes}
                        countries={countries}
                        errors={form.errors as Record<string, string>}
                        label={mark('identity_document', 'ID document')}
                        word={word}
                    />
                )}
            </FieldGroup>
        </CardSection>
    );
}

/** The offers switch. Hidden when the Layout tab hides it. */
export function PreferencesSection({ form, word, has }: CardSectionProps) {
    if (!has('marketing_consent')) {
        return null;
    }

    return (
        <CardSection title={word('Preferences')}>
            <Field orientation="horizontal">
                <Checkbox
                    id="card-marketing_consent"
                    checked={form.data.marketing_consent}
                    onCheckedChange={(checked) =>
                        form.setData('marketing_consent', checked)
                    }
                />
                <FieldLabel htmlFor="card-marketing_consent">
                    {word('I agree to receive offers')}
                </FieldLabel>
            </Field>
        </CardSection>
    );
}
