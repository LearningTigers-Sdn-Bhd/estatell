import { Head, useForm, setLayoutProps } from '@inertiajs/react';
import { Info, TriangleAlert } from 'lucide-react';
import PropertyBasicsController from '@/actions/App/Http/Controllers/Onboarding/PropertyBasicsController';
import { CountrySelect } from '@/components/pms/country-select';
import { EnumSelect } from '@/components/pms/enum-select';
import {
    BusinessDetailsFields,
    toLicenceRows,
} from '@/components/pms/onboarding/business-details-fields';
import type { BusinessValues } from '@/components/pms/onboarding/business-details-fields';
import { StepForm } from '@/components/pms/onboarding/step-form';
import type { OnboardingLayoutProps } from '@/components/pms/onboarding/onboarding-steps';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import Heading from '@/components/heading';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';

type Option = { value: string; label: string };
type Region = { currency: string; timeZones: string[] };
type Profile = App.Data.Property.CountryProfileData;

const FORM_ID = 'basics-form';

export default function Basics({
    basics,
    propertyTypes,
    countries,
    currencies,
    malaysianStates,
    regions,
    profiles,
    maxLicences,
}: {
    basics: App.Data.Property.PropertyBasicsData;
    propertyTypes: Option[];
    countries: Option[];
    currencies: Option[];
    malaysianStates: Option[];
    regions: Record<string, Region>;
    profiles: { MY: Profile; default: Profile };
    maxLicences: number;
}) {
    const form = useForm({
        name: basics.name,
        type: basics.type,
        country_code: basics.countryCode,
        currency: basics.currency,
        time_zone: basics.timeZone,
        city: basics.city ?? '',
        address_line1: basics.addressLine1 ?? '',
        address_line2: basics.addressLine2 ?? '',
        state_code: basics.stateCode ?? '',
        postal_code: basics.postalCode ?? '',
        legal_name: basics.legalName ?? '',
        registration_number: basics.registrationNumber ?? '',
        tin: basics.tin ?? '',
        licences: toLicenceRows(basics.licences),
    });
    const guard = useUnsavedChanges(form.isDirty);
    const errors = form.errors as Record<string, string | undefined>;
    const locked = basics.locked;
    const profile =
        form.data.country_code === 'MY' ? profiles.MY : profiles.default;
    const zones = (regions[form.data.country_code]?.timeZones ?? []).map(
        (zone) => ({ value: zone, label: zone }),
    );

    const changeCountry = (code: string) => {
        const region = regions[code];

        form.setData((data) => ({
            ...data,
            country_code: code,
            state_code: '',
            currency: region?.currency ?? data.currency,
            time_zone: region?.timeZones[0] ?? data.time_zone,
        }));
    };

    const setBusiness = <K extends keyof BusinessValues>(
        key: K,
        value: BusinessValues[K],
    ) => form.setData((data) => ({ ...data, [key]: value }));

    form.transform((data) =>
        locked
            ? {
                  ...data,
                  country_code: undefined,
                  currency: undefined,
                  time_zone: undefined,
              }
            : data,
    );

    setLayoutProps<OnboardingLayoutProps>({
        step: 'basics',
        footer: 'auto',
        formId: FORM_ID,
        processing: form.processing,
    });

    const save = (onSuccess?: () => void) =>
        form.put(PropertyBasicsController.update.url(), {
            preserveScroll: true,
            onSuccess,
            onError: (errors) => {
                const first = Object.keys(errors)[0];

                if (first) {
                    document.getElementById(first)?.focus();
                }
            },
        });

    return (
        <>
            <Head title="Property basics" />
            <Heading
                title="Property basics"
                description="Tell us about your place. We prepare its settings for the country you choose."
            />
            <StepForm
                id={FORM_ID}
                onSubmit={(event) => {
                    event.preventDefault();
                    save();
                }}
            >
                <div className="grid gap-5 md:grid-cols-2">
                    <Field className="md:col-span-2">
                        <FieldLabel htmlFor="name">Property name</FieldLabel>
                        <Input
                            id="name"
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            aria-invalid={!!form.errors.name}
                        />
                        <FieldError>{form.errors.name}</FieldError>
                    </Field>
                    <Field>
                        <FieldLabel htmlFor="type">Property type</FieldLabel>
                        <Select
                            items={propertyTypes}
                            value={form.data.type}
                            onValueChange={(value) =>
                                form.setData('type', value)
                            }
                        >
                            <SelectTrigger
                                id="type"
                                aria-invalid={!!form.errors.type}
                            >
                                <SelectValue placeholder="Choose a type" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectGroup>
                                    {propertyTypes.map((option) => (
                                        <SelectItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                        <FieldError>{form.errors.type}</FieldError>
                    </Field>
                    {locked ? (
                        <Field className="md:col-span-2">
                            <FieldLabel>Country</FieldLabel>
                            <p className="text-sm">
                                {[
                                    basics.countryName ?? basics.countryCode,
                                    basics.currency,
                                    basics.timeZone,
                                ].join(' · ')}
                            </p>
                        </Field>
                    ) : (
                        <>
                            <Field className="md:col-span-2">
                                <FieldLabel htmlFor="country_code">
                                    Country
                                </FieldLabel>
                                <CountrySelect
                                    id="country_code"
                                    value={form.data.country_code}
                                    onValueChange={changeCountry}
                                    options={countries}
                                    invalid={!!errors.country_code}
                                />
                                <FieldError>{errors.country_code}</FieldError>
                            </Field>
                            {!profile.fixedRegion && (
                                <>
                                    <Field>
                                        <FieldLabel htmlFor="currency">
                                            Currency
                                        </FieldLabel>
                                        <CountrySelect
                                            id="currency"
                                            value={form.data.currency}
                                            onValueChange={(value) =>
                                                form.setData('currency', value)
                                            }
                                            options={currencies}
                                            placeholder="Search currencies"
                                            emptyText="No matching currencies."
                                            invalid={!!errors.currency}
                                        />
                                        <FieldError>
                                            {errors.currency}
                                        </FieldError>
                                    </Field>
                                    <Field>
                                        <FieldLabel htmlFor="time_zone">
                                            Time zone
                                        </FieldLabel>
                                        <EnumSelect
                                            id="time_zone"
                                            options={zones}
                                            value={form.data.time_zone}
                                            onValueChange={(value) =>
                                                form.setData('time_zone', value)
                                            }
                                            placeholder="Choose a time zone"
                                            className="w-full"
                                            aria-invalid={!!errors.time_zone}
                                        />
                                        <FieldError>
                                            {errors.time_zone}
                                        </FieldError>
                                    </Field>
                                </>
                            )}
                        </>
                    )}
                    <Field>
                        <FieldLabel htmlFor="city">City</FieldLabel>
                        <Input
                            id="city"
                            value={form.data.city}
                            onChange={(event) =>
                                form.setData('city', event.target.value)
                            }
                            aria-invalid={!!form.errors.city}
                        />
                        <FieldError>{form.errors.city}</FieldError>
                    </Field>
                    <Field className="md:col-span-2">
                        <FieldLabel htmlFor="address_line1">
                            Address line 1
                        </FieldLabel>
                        <Input
                            id="address_line1"
                            value={form.data.address_line1}
                            onChange={(event) =>
                                form.setData(
                                    'address_line1',
                                    event.target.value,
                                )
                            }
                            aria-invalid={!!form.errors.address_line1}
                        />
                        <FieldError>{form.errors.address_line1}</FieldError>
                    </Field>
                    <Field className="md:col-span-2">
                        <FieldLabel htmlFor="address_line2">
                            Address line 2 (optional)
                        </FieldLabel>
                        <Input
                            id="address_line2"
                            value={form.data.address_line2}
                            onChange={(event) =>
                                form.setData(
                                    'address_line2',
                                    event.target.value,
                                )
                            }
                            aria-invalid={!!form.errors.address_line2}
                        />
                        <FieldError>{form.errors.address_line2}</FieldError>
                    </Field>
                    <Field>
                        <FieldLabel htmlFor="state_code">
                            State (optional)
                        </FieldLabel>
                        {form.data.country_code === 'MY' ? (
                            <EnumSelect
                                id="state_code"
                                options={malaysianStates}
                                allLabel="No state"
                                value={form.data.state_code}
                                onValueChange={(value) =>
                                    form.setData('state_code', value)
                                }
                                placeholder="Choose a state"
                                className="w-full"
                                aria-invalid={!!errors.state_code}
                            />
                        ) : (
                            <Input
                                id="state_code"
                                value={form.data.state_code}
                                onChange={(event) =>
                                    form.setData(
                                        'state_code',
                                        event.target.value,
                                    )
                                }
                                aria-invalid={!!errors.state_code}
                            />
                        )}
                        <FieldError>{errors.state_code}</FieldError>
                    </Field>
                    <Field>
                        <FieldLabel htmlFor="postal_code">
                            Postcode (optional)
                        </FieldLabel>
                        <Input
                            id="postal_code"
                            value={form.data.postal_code}
                            onChange={(event) =>
                                form.setData('postal_code', event.target.value)
                            }
                            aria-invalid={!!form.errors.postal_code}
                        />
                        <FieldError>{form.errors.postal_code}</FieldError>
                    </Field>
                </div>
                <Alert
                    className={
                        profile.fixedRegion
                            ? undefined
                            : 'border-warning/40 bg-warning/10'
                    }
                >
                    {profile.fixedRegion ? (
                        <Info />
                    ) : (
                        <TriangleAlert className="text-warning" />
                    )}
                    <AlertDescription>{profile.notice}</AlertDescription>
                </Alert>
                <BusinessDetailsFields
                    values={{
                        legal_name: form.data.legal_name,
                        registration_number: form.data.registration_number,
                        tin: form.data.tin,
                        licences: form.data.licences,
                    }}
                    errors={errors}
                    registrationNumberLabel={profile.registrationNumberLabel}
                    tinLabel={profile.tinLabel}
                    maxLicences={maxLicences}
                    onChange={setBusiness}
                />
            </StepForm>
            <UnsavedChangesDialog
                guard={guard}
                name="Property basics"
                saving={form.processing}
                onSave={() => save(guard.proceed)}
            />
        </>
    );
}
