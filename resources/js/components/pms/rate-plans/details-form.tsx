import { useForm } from '@inertiajs/react';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import { update } from '@/routes/settings/rate-plans';

type RatePlan = App.Data.Rates.RatePlanData;

function AmountField({
    id,
    label,
    description,
    currency,
    value,
    error,
    onChange,
}: {
    id: string;
    label: string;
    description: string;
    currency: App.Enums.Currency;
    value: string;
    error?: string;
    onChange: (value: string) => void;
}) {
    return (
        <Field data-invalid={!!error}>
            <FieldLabel htmlFor={id}>{label}</FieldLabel>
            <InputGroup className="sm:max-w-60">
                <InputGroupAddon>{currency}</InputGroupAddon>
                <InputGroupInput
                    id={id}
                    type="number"
                    min="0"
                    max="9999999999.99"
                    step="0.01"
                    inputMode="decimal"
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    aria-invalid={!!error}
                />
            </InputGroup>
            <FieldDescription>{description}</FieldDescription>
            <FieldError>{error}</FieldError>
        </Field>
    );
}

/**
 * Name, description and the extra guest charges that fit the plan's pricing basis.
 * The pricing basis itself is fixed when the plan is created.
 */
export function DetailsForm({
    ratePlan,
    currency,
    nightsLockedReason = null,
}: {
    ratePlan: RatePlan;
    currency: App.Enums.Currency;
    /** Why the nights of a package cannot change, or null when they can. */
    nightsLockedReason?: string | null;
}) {
    const form = useForm({
        name: ratePlan.name,
        description: ratePlan.description ?? '',
        extra_adult_amount: ratePlan.extraAdultAmount,
        extra_child_amount: ratePlan.extraChildAmount,
        single_supplement: ratePlan.singleSupplement,
        fixed_nights: String(ratePlan.fixedNights ?? ''),
    });
    const guard = useUnsavedChanges(form.isDirty);
    const basis = ratePlan.pricingBasis;
    const isPackage = basis === 'package';
    const extraGuests =
        basis === 'per_room' ||
        (isPackage && ratePlan.packageUnit === 'per_room');
    const period = isPackage ? 'Per stay' : 'Per night';

    const save = (onSaved?: () => void) => {
        form.put(update.url({ ratePlan: ratePlan.id }), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                form.setDefaults();
                onSaved?.();
            },
        });
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        save();
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-5">
            <FieldGroup>
                <Field data-invalid={!!form.errors.name}>
                    <FieldLabel htmlFor="rate-plan-name">Name</FieldLabel>
                    <Input
                        id="rate-plan-name"
                        maxLength={100}
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        aria-invalid={!!form.errors.name}
                    />
                    <FieldError>{form.errors.name}</FieldError>
                </Field>
                <Field data-invalid={!!form.errors.description}>
                    <FieldLabel htmlFor="rate-plan-description">
                        Description
                    </FieldLabel>
                    <Textarea
                        id="rate-plan-description"
                        maxLength={2000}
                        value={form.data.description}
                        onChange={(event) =>
                            form.setData('description', event.target.value)
                        }
                        aria-invalid={!!form.errors.description}
                    />
                    <FieldDescription>
                        Shown to guests and agents.
                    </FieldDescription>
                    <FieldError>{form.errors.description}</FieldError>
                </Field>
                {isPackage && (
                    <Field data-invalid={!!form.errors.fixed_nights}>
                        <FieldLabel htmlFor="fixed_nights">Nights</FieldLabel>
                        <Input
                            id="fixed_nights"
                            type="number"
                            min="1"
                            max="30"
                            inputMode="numeric"
                            className="sm:max-w-32"
                            disabled={nightsLockedReason !== null}
                            value={form.data.fixed_nights}
                            onChange={(event) =>
                                form.setData('fixed_nights', event.target.value)
                            }
                            aria-invalid={!!form.errors.fixed_nights}
                        />
                        <FieldDescription>
                            {nightsLockedReason ??
                                'The stay is fixed. The booking form sets departure to arrival plus these nights. Existing bookings keep their prices.'}
                        </FieldDescription>
                        <FieldError>{form.errors.fixed_nights}</FieldError>
                    </Field>
                )}
                {extraGuests && (
                    <>
                        <AmountField
                            id="extra_adult_amount"
                            label="Extra adult"
                            description={`${period}, for each adult above the guests the room type includes.`}
                            currency={currency}
                            value={form.data.extra_adult_amount}
                            error={form.errors.extra_adult_amount}
                            onChange={(value) =>
                                form.setData('extra_adult_amount', value)
                            }
                        />
                        <AmountField
                            id="extra_child_amount"
                            label="Extra child"
                            description={`${period}, for each child above the guests the room type includes.`}
                            currency={currency}
                            value={form.data.extra_child_amount}
                            error={form.errors.extra_child_amount}
                            onChange={(value) =>
                                form.setData('extra_child_amount', value)
                            }
                        />
                    </>
                )}
                {basis === 'per_guest' && (
                    <AmountField
                        id="single_supplement"
                        label="Single supplement"
                        description="Per night, added when one adult stays alone and no price is set for one adult."
                        currency={currency}
                        value={form.data.single_supplement}
                        error={form.errors.single_supplement}
                        onChange={(value) =>
                            form.setData('single_supplement', value)
                        }
                    />
                )}
            </FieldGroup>
            <footer className="flex justify-end">
                <Button
                    type="submit"
                    disabled={form.processing}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    Save details
                </Button>
            </footer>
            <UnsavedChangesDialog
                guard={guard}
                name={ratePlan.name}
                saving={form.processing}
                onSave={() => save(guard.proceed)}
            />
        </form>
    );
}
