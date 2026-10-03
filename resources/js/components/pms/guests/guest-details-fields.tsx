import type { ReactNode } from 'react';
import { CountrySelect } from '@/components/pms/country-select';
import { DatePicker } from '@/components/pms/date-picker';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

/** The personal details and address of a guest, as the forms hold them. */
export type GuestDetails = {
    nationality_code: string;
    date_of_birth: string;
    gender: string;
    preferred_locale: string;
    address_line1: string;
    address_line2: string;
    city: string;
    state: string;
    postal_code: string;
    country_code: string;
};

export const EMPTY_GUEST_DETAILS: GuestDetails = {
    nationality_code: '',
    date_of_birth: '',
    gender: '',
    preferred_locale: '',
    address_line1: '',
    address_line2: '',
    city: '',
    state: '',
    postal_code: '',
    country_code: '',
};

type Errors = Record<string, string | undefined>;

type Props = {
    values: GuestDetails;
    errors: Errors;
    onChange: (field: keyof GuestDetails, value: string) => void;
    countries: EnumOption[];
    /** Put in front of every field id, so two forms on one page do not share ids. */
    idPrefix?: string;
    /** Put in front of every error key, for a nested form such as `guests.0.`. */
    errorPrefix?: string;
};

type PersonalProps = Props & {
    genders: EnumOption[];
    locales: EnumOption[];
    /** Called with the new date of birth, for the duplicate check. */
    onDateOfBirthChange?: (value: string) => void;
    /** Fields per row from `lg`. Two by default; the booking form uses three. */
    columns?: 2 | 3;
    /** A field put first in the same grid, for example the Email in the booking form. */
    leading?: ReactNode;
};

function useParts({
    values,
    errors,
    onChange,
    idPrefix = '',
    errorPrefix = '',
}: Props) {
    const error = (field: keyof GuestDetails) =>
        errors[`${errorPrefix}${field}`];

    return {
        error,
        id: (field: keyof GuestDetails) => `${idPrefix}${field}`,
        values,
        onChange,
    };
}

function TextField({
    field,
    label,
    maxLength,
    placeholder,
    parts,
    autoComplete,
}: {
    field: 'address_line1' | 'address_line2' | 'city' | 'state' | 'postal_code';
    label: string;
    maxLength: number;
    placeholder: string;
    parts: ReturnType<typeof useParts>;
    autoComplete?: string;
}) {
    return (
        <Field data-invalid={!!parts.error(field)}>
            <FieldLabel htmlFor={parts.id(field)}>{label}</FieldLabel>
            <Input
                id={parts.id(field)}
                value={parts.values[field]}
                onChange={(event) => parts.onChange(field, event.target.value)}
                maxLength={maxLength}
                placeholder={placeholder}
                autoComplete={autoComplete}
                aria-invalid={!!parts.error(field)}
            />
            <FieldError>{parts.error(field)}</FieldError>
        </Field>
    );
}

function CountryField({
    field,
    label,
    countries,
    parts,
}: {
    field: 'nationality_code' | 'country_code';
    label: string;
    countries: EnumOption[];
    parts: ReturnType<typeof useParts>;
}) {
    return (
        <Field data-invalid={!!parts.error(field)}>
            <FieldLabel htmlFor={parts.id(field)}>{label}</FieldLabel>
            <CountrySelect
                id={parts.id(field)}
                options={countries}
                value={parts.values[field]}
                onValueChange={(value) => parts.onChange(field, value)}
                invalid={!!parts.error(field)}
            />
            <FieldError>{parts.error(field)}</FieldError>
        </Field>
    );
}

function SelectField({
    field,
    label,
    options,
    placeholder,
    parts,
}: {
    field: 'gender' | 'preferred_locale';
    label: string;
    options: EnumOption[];
    placeholder: string;
    parts: ReturnType<typeof useParts>;
}) {
    return (
        <Field data-invalid={!!parts.error(field)}>
            <FieldLabel htmlFor={parts.id(field)}>{label}</FieldLabel>
            <EnumSelect
                id={parts.id(field)}
                options={options}
                allLabel={placeholder}
                value={parts.values[field]}
                onValueChange={(value) => parts.onChange(field, value)}
                aria-invalid={!!parts.error(field)}
                className="w-full"
            />
            <FieldError>{parts.error(field)}</FieldError>
        </Field>
    );
}

/**
 * Nationality, date of birth, gender and preferred language. Shared by the guest page and the booking form.
 */
export function GuestPersonalFields(props: PersonalProps) {
    const parts = useParts(props);
    const today = new Date().toLocaleDateString('en-CA');

    return (
        <FieldGroup>
            <div
                className={cn(
                    'grid gap-4 sm:grid-cols-2',
                    props.columns === 3 && 'lg:grid-cols-3',
                )}
            >
                {props.leading}
                <CountryField
                    field="nationality_code"
                    label="Nationality"
                    countries={props.countries}
                    parts={parts}
                />
                <Field data-invalid={!!parts.error('date_of_birth')}>
                    <FieldLabel htmlFor={parts.id('date_of_birth')}>
                        Date of birth
                    </FieldLabel>
                    <DatePicker
                        id={parts.id('date_of_birth')}
                        variant="dropdown"
                        max={today}
                        placeholder="Pick date of birth"
                        value={props.values.date_of_birth}
                        onChange={(value) => {
                            props.onChange('date_of_birth', value);
                            props.onDateOfBirthChange?.(value);
                        }}
                        aria-invalid={!!parts.error('date_of_birth')}
                    />
                    <FieldError>{parts.error('date_of_birth')}</FieldError>
                </Field>
                <SelectField
                    field="gender"
                    label="Gender"
                    options={props.genders}
                    placeholder="Select gender"
                    parts={parts}
                />
                <SelectField
                    field="preferred_locale"
                    label="Preferred language"
                    options={props.locales}
                    placeholder="Select language"
                    parts={parts}
                />
            </div>
        </FieldGroup>
    );
}

/**
 * The address: two lines, city, state, postal code and country.
 */
export function GuestAddressFields(props: Props) {
    const parts = useParts(props);

    return (
        <FieldGroup>
            <TextField
                field="address_line1"
                label="Address line 1"
                maxLength={150}
                placeholder="Street and number"
                autoComplete="off"
                parts={parts}
            />
            <TextField
                field="address_line2"
                label="Address line 2"
                maxLength={150}
                placeholder="Apartment, unit or building"
                autoComplete="off"
                parts={parts}
            />
            <div className="grid gap-4 sm:grid-cols-2">
                <TextField
                    field="city"
                    label="City"
                    maxLength={100}
                    placeholder="Kuala Lumpur"
                    parts={parts}
                />
                <TextField
                    field="state"
                    label="State"
                    maxLength={100}
                    placeholder="Selangor"
                    parts={parts}
                />
                <TextField
                    field="postal_code"
                    label="Postal code"
                    maxLength={20}
                    placeholder="50450"
                    parts={parts}
                />
                <CountryField
                    field="country_code"
                    label="Country"
                    countries={props.countries}
                    parts={parts}
                />
            </div>
        </FieldGroup>
    );
}

/**
 * All the details in one block, for the booking form's "More guest details".
 */
export function GuestDetailsFields(props: PersonalProps) {
    return (
        <div className="flex flex-col gap-6">
            <GuestPersonalFields {...props} />
            <GuestAddressFields {...props} />
        </div>
    );
}
