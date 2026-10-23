import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { EnumOption } from '@/components/pms/enum-select';
import { DuplicateWarning } from '@/components/pms/guests/duplicate-warning';
import {
    GuestAddressFields,
    GuestPersonalFields,
} from '@/components/pms/guests/guest-details-fields';
import { MyKadScanner } from '@/components/pms/mykad-scanner';
import { TagInput } from '@/components/pms/guests/tag-input';
import { PhoneInput } from '@/components/pms/phone-input';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Button } from '@/components/ui/button';
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
import type { GuestMatchCriteria } from '@/hooks/use-guest-matches';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import { update } from '@/routes/guests';

type Guest = App.Data.Guests.GuestData;
type Tag = App.Data.Guests.TagData;

type TextField = 'full_name' | 'email';

type Props = {
    guest: Guest;
    countries: EnumOption[];
    genders: EnumOption[];
    locales: EnumOption[];
    tags: Tag[];
};

/**
 * The guest's details as an always-open form on the Details tab. New guests start in the add-guest sheet.
 * Tags are sent as names; new names create tags on save. Leaving with unsaved changes asks first.
 * The caller keys it by `guestFormKey`, so it starts over from the saved values after every save.
 */
export function guestFormKey(guest: Guest): string {
    return JSON.stringify([
        guest.fullName,
        guest.email,
        guest.phone,
        guest.nationalityCode,
        guest.dateOfBirth,
        guest.gender,
        guest.preferredLocale,
        guest.addressLine1,
        guest.addressLine2,
        guest.city,
        guest.state,
        guest.postalCode,
        guest.countryCode,
        guest.tags.map((tag) => tag.name),
    ]);
}

export function GuestForm({ guest, countries, genders, locales, tags }: Props) {
    const form = useForm({
        full_name: guest.fullName ?? '',
        email: guest.email ?? '',
        phone: guest.phone ?? '',
        nationality_code: guest.nationalityCode ?? '',
        date_of_birth: guest.dateOfBirth ?? '',
        gender: guest.gender ?? '',
        preferred_locale: guest.preferredLocale ?? '',
        address_line1: guest.addressLine1 ?? '',
        address_line2: guest.addressLine2 ?? '',
        city: guest.city ?? '',
        state: guest.state ?? '',
        postal_code: guest.postalCode ?? '',
        country_code: guest.countryCode ?? '',
        tags: (guest.tags ?? []).map((tag) => tag.name),
    });
    const guard = useUnsavedChanges(form.isDirty);
    const { data, errors } = form;
    const [checked, setChecked] = useState<GuestMatchCriteria>({});

    // Possible duplicates are looked up when a field is left, not on every key press.
    const checkForDuplicates = () =>
        setChecked({
            name: data.full_name,
            email: data.email,
            phone: data.phone,
            date_of_birth: data.date_of_birth,
            exclude_guest_id: guest.id,
        });

    const save = (onSaved?: () => void) => {
        const options = {
            onSuccess: () => {
                form.setDefaults();
                onSaved?.();
            },
        };

        form.put(update.url({ guest: guest.id }), options);
    };

    const text = (
        name: TextField,
        label: string,
        props: {
            maxLength: number;
            placeholder: string;
            type?: string;
            autoComplete?: string;
        },
    ) => (
        <Field data-invalid={!!errors[name]}>
            <FieldLabel htmlFor={name}>{label}</FieldLabel>
            <Input
                id={name}
                value={data[name]}
                onChange={(event) => form.setData(name, event.target.value)}
                onBlur={checkForDuplicates}
                aria-invalid={!!errors[name]}
                {...props}
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
            className="flex flex-col gap-8"
        >
            <div className="grid gap-8 lg:grid-cols-2">
                <div className="flex flex-col gap-8">
                    <MyKadScanner
                        onScan={(card) => {
                            form.setData({
                                ...form.data,
                                full_name: card.name,
                                nationality_code: 'MY',
                                date_of_birth: card.date_of_birth,
                                gender: card.gender,
                                address_line1: card.address_line1,
                                address_line2: card.address_line2,
                                city: card.city,
                                state: card.state,
                                postal_code: card.postal_code,
                                country_code: 'MY',
                            });
                        }}
                    />
                    <FieldSet>
                        <FieldLegend>Contact</FieldLegend>
                        <FieldGroup>
                            {text('full_name', 'Full name', {
                                maxLength: 150,
                                placeholder: 'Aisyah binti Rahman',
                                autoComplete: 'off',
                            })}
                            <div className="grid gap-4 sm:grid-cols-2">
                                {text('email', 'Email', {
                                    maxLength: 255,
                                    placeholder: 'guest@example.com',
                                    type: 'email',
                                    autoComplete: 'off',
                                })}
                                <Field data-invalid={!!errors.phone}>
                                    <FieldLabel htmlFor="phone">
                                        Phone
                                    </FieldLabel>
                                    <PhoneInput
                                        id="phone"
                                        value={data.phone}
                                        onChange={(value) =>
                                            form.setData('phone', value)
                                        }
                                        onBlur={checkForDuplicates}
                                        invalid={!!errors.phone}
                                    />
                                    <FieldDescription>
                                        Without a country code, the number is
                                        read as a local number.
                                    </FieldDescription>
                                    <FieldError>{errors.phone}</FieldError>
                                </Field>
                            </div>
                            <DuplicateWarning criteria={checked} />
                        </FieldGroup>
                    </FieldSet>

                    <FieldSet>
                        <FieldLegend>Personal details</FieldLegend>
                        <GuestPersonalFields
                            values={data}
                            errors={errors}
                            onChange={(field, value) =>
                                form.setData(field, value)
                            }
                            countries={countries}
                            genders={genders}
                            locales={locales}
                            onDateOfBirthChange={(value) =>
                                setChecked({
                                    name: data.full_name,
                                    email: data.email,
                                    phone: data.phone,
                                    date_of_birth: value,
                                    exclude_guest_id: guest.id,
                                })
                            }
                        />
                    </FieldSet>

                    <FieldSet>
                        <FieldLegend>Tags</FieldLegend>
                        <Field data-invalid={!!errors.tags}>
                            <TagInput
                                id="tags"
                                value={data.tags}
                                onChange={(value) =>
                                    form.setData('tags', value)
                                }
                                suggestions={tags.map((tag) => tag.name)}
                                invalid={!!errors.tags}
                            />
                            <FieldError>{errors.tags}</FieldError>
                        </Field>
                    </FieldSet>
                </div>
                <div className="flex flex-col gap-8">
                    <FieldSet>
                        <FieldLegend>Address</FieldLegend>
                        <GuestAddressFields
                            values={data}
                            errors={errors}
                            onChange={(field, value) =>
                                form.setData(field, value)
                            }
                            countries={countries}
                        />
                    </FieldSet>
                </div>
            </div>

            <footer className="flex justify-end gap-2">
                <Button
                    type="button"
                    variant="outline"
                    disabled={!form.isDirty || form.processing}
                    onClick={() => form.reset()}
                >
                    Discard changes
                </Button>
                <Button
                    type="submit"
                    disabled={!form.isDirty || form.processing}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    Save changes
                </Button>
            </footer>
            <UnsavedChangesDialog
                guard={guard}
                name={guest.fullName}
                saving={form.processing}
                onSave={() => save(guard.proceed)}
            />
        </form>
    );
}
