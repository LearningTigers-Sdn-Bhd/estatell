import { Link, useForm } from '@inertiajs/react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { TagInput } from '@/components/pms/guests/tag-input';
import { PhoneInput } from '@/components/pms/phone-input';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import { index, show, store, update } from '@/routes/guests';

type Guest = App.Data.Guests.GuestData;
type Tag = App.Data.Guests.TagData;

type TextField =
    | 'full_name'
    | 'email'
    | 'date_of_birth'
    | 'address_line1'
    | 'address_line2'
    | 'city'
    | 'state'
    | 'postal_code';

type Props = {
    guest: Guest | null;
    countries: EnumOption[];
    genders: EnumOption[];
    locales: EnumOption[];
    tags: Tag[];
    defaultCountryCode: string | null;
};

/**
 * The full-page guest form, for both adding and editing. Tags are sent as names; new names create tags on save.
 */
export function GuestForm({
    guest,
    countries,
    genders,
    locales,
    tags,
    defaultCountryCode,
}: Props) {
    const form = useForm({
        full_name: guest?.fullName ?? '',
        email: guest?.email ?? '',
        phone: guest?.phone ?? '',
        nationality_code: guest?.nationalityCode ?? '',
        date_of_birth: guest?.dateOfBirth ?? '',
        gender: guest?.gender ?? '',
        preferred_locale: guest?.preferredLocale ?? '',
        address_line1: guest?.addressLine1 ?? '',
        address_line2: guest?.addressLine2 ?? '',
        city: guest?.city ?? '',
        state: guest?.state ?? '',
        postal_code: guest?.postalCode ?? '',
        country_code: guest
            ? (guest.countryCode ?? '')
            : (defaultCountryCode ?? ''),
        tags: (guest?.tags ?? []).map((tag) => tag.name),
    });
    const guard = useUnsavedChanges(form.isDirty);
    const { data, errors } = form;

    const save = (onSaved?: () => void) => {
        const options = {
            onSuccess: () => {
                form.setDefaults();
                onSaved?.();
            },
        };

        if (guest) {
            form.put(update.url({ guest: guest.id }), options);
        } else {
            form.post(store.url(), options);
        }
    };

    const text = (
        name: TextField,
        label: string,
        props: { maxLength: number; type?: string; autoComplete?: string },
    ) => (
        <Field data-invalid={!!errors[name]}>
            <FieldLabel htmlFor={name}>{label}</FieldLabel>
            <Input
                id={name}
                value={data[name]}
                onChange={(event) => form.setData(name, event.target.value)}
                aria-invalid={!!errors[name]}
                {...props}
            />
            <FieldError>{errors[name]}</FieldError>
        </Field>
    );

    const select = (
        name:
            | 'nationality_code'
            | 'gender'
            | 'preferred_locale'
            | 'country_code',
        label: string,
        options: EnumOption[],
    ) => (
        <Field data-invalid={!!errors[name]}>
            <FieldLabel htmlFor={name}>{label}</FieldLabel>
            <EnumSelect
                id={name}
                options={options}
                allLabel="Not set"
                value={data[name]}
                onValueChange={(value) => form.setData(name, value)}
                aria-invalid={!!errors[name]}
                className="w-full"
            />
            <FieldError>{errors[name]}</FieldError>
        </Field>
    );

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                save();
            }}
            className="flex max-w-3xl flex-col gap-8"
        >
            <FieldSet>
                <FieldLegend>Contact</FieldLegend>
                <FieldGroup>
                    {text('full_name', 'Full name', {
                        maxLength: 150,
                        autoComplete: 'off',
                    })}
                    <div className="grid gap-4 sm:grid-cols-2">
                        {text('email', 'Email', {
                            maxLength: 255,
                            type: 'email',
                            autoComplete: 'off',
                        })}
                        <Field data-invalid={!!errors.phone}>
                            <FieldLabel htmlFor="phone">Phone</FieldLabel>
                            <PhoneInput
                                id="phone"
                                value={data.phone}
                                onChange={(value) =>
                                    form.setData('phone', value)
                                }
                                invalid={!!errors.phone}
                            />
                            <FieldDescription>
                                Without a country code, the number is read as a
                                local number.
                            </FieldDescription>
                            <FieldError>{errors.phone}</FieldError>
                        </Field>
                    </div>
                </FieldGroup>
            </FieldSet>

            <FieldSet>
                <FieldLegend>Personal details</FieldLegend>
                <FieldGroup>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {select('nationality_code', 'Nationality', countries)}
                        {text('date_of_birth', 'Date of birth', {
                            maxLength: 10,
                            type: 'date',
                        })}
                        {select('gender', 'Gender', genders)}
                        {select(
                            'preferred_locale',
                            'Preferred language',
                            locales,
                        )}
                    </div>
                </FieldGroup>
            </FieldSet>

            <FieldSet>
                <FieldLegend>Address</FieldLegend>
                <FieldGroup>
                    {text('address_line1', 'Address line 1', {
                        maxLength: 150,
                        autoComplete: 'off',
                    })}
                    {text('address_line2', 'Address line 2', {
                        maxLength: 150,
                        autoComplete: 'off',
                    })}
                    <div className="grid gap-4 sm:grid-cols-2">
                        {text('city', 'City', { maxLength: 100 })}
                        {text('state', 'State', { maxLength: 100 })}
                        {text('postal_code', 'Postal code', { maxLength: 20 })}
                        {select('country_code', 'Country', countries)}
                    </div>
                </FieldGroup>
            </FieldSet>

            <FieldSet>
                <FieldLegend>Tags</FieldLegend>
                <Field data-invalid={!!errors.tags}>
                    <TagInput
                        id="tags"
                        value={data.tags}
                        onChange={(value) => form.setData('tags', value)}
                        suggestions={tags.map((tag) => tag.name)}
                        invalid={!!errors.tags}
                    />
                    <FieldError>{errors.tags}</FieldError>
                </Field>
            </FieldSet>

            <footer className="flex justify-end gap-2">
                <Link
                    href={guest ? show.url({ guest: guest.id }) : index.url()}
                    className={buttonVariants({ variant: 'outline' })}
                >
                    Cancel
                </Link>
                <Button
                    type="submit"
                    disabled={form.processing}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    {guest ? 'Save guest' : 'Add guest'}
                </Button>
            </footer>
            <UnsavedChangesDialog
                guard={guard}
                name={guest?.fullName ?? 'this guest'}
                saving={form.processing}
                onSave={() => save(guard.proceed)}
            />
        </form>
    );
}
