import { Head, useForm, setLayoutProps } from '@inertiajs/react';
import PropertyBasicsController from '@/actions/App/Http/Controllers/Onboarding/PropertyBasicsController';
import { StepForm } from '@/components/pms/onboarding/step-form';
import type { OnboardingLayoutProps } from '@/components/pms/onboarding/onboarding-steps';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import Heading from '@/components/heading';
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

const FORM_ID = 'basics-form';

export default function Basics({
    basics,
    propertyTypes,
}: {
    basics: App.Data.Property.PropertyBasicsData;
    propertyTypes: Option[];
}) {
    const form = useForm({
        name: basics.name,
        type: basics.type,
        city: basics.city ?? '',
        address_line1: basics.addressLine1 ?? '',
        address_line2: basics.addressLine2 ?? '',
        state_code: basics.stateCode ?? '',
        postal_code: basics.postalCode ?? '',
    });
    const guard = useUnsavedChanges(form.isDirty);

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
                description="Tell us about your place. We will prepare its settings for Malaysia."
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
                        <Input
                            id="state_code"
                            value={form.data.state_code}
                            onChange={(event) =>
                                form.setData('state_code', event.target.value)
                            }
                            aria-invalid={!!form.errors.state_code}
                        />
                        <FieldError>{form.errors.state_code}</FieldError>
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
