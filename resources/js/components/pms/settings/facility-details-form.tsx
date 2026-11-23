import { useForm } from '@inertiajs/react';
import { Lock, Package, User, Users } from 'lucide-react';
import type { FormEvent } from 'react';
import { ChargeItemTaxesField } from '@/components/pms/charge-item-taxes-field';
import { ChoiceCards } from '@/components/pms/choice-cards';
import type { Choice } from '@/components/pms/choice-cards';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { MoneyInput } from '@/components/pms/money-input';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
    InputGroupText,
} from '@/components/ui/input-group';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import { update } from '@/routes/settings/facilities';

type Facility = App.Data.Facilities.FacilityData;
type Tax = App.Data.Catalogues.ChargeItemTaxData;
export type SharingOption = {
    value: string;
    label: string;
    description: string;
};

const SHARING_ICONS: Record<string, Choice['icon']> = {
    private: Lock,
    shared: Users,
};

/** Sharing as choice cards with an icon per case. */
export function sharingChoices(options: SharingOption[]): Choice[] {
    return options.map((option) => ({
        ...option,
        icon: SHARING_ICONS[option.value] ?? Lock,
    }));
}

export type CapacityUnitOption = {
    value: string;
    label: string;
    title: string;
    description: string;
};

const CAPACITY_UNIT_ICONS: Record<string, Choice['icon']> = {
    pax: User,
    units: Package,
};

/**
 * Count by (People or Items) as choice cards, then Capacity with the unit after the number. Shared by the Add
 * facility Sheet and the Details tab.
 */
export function CapacityFields({
    capacityUnits,
    sharing,
    capacity,
    capacityUnit,
    onCapacityChange,
    onCapacityUnitChange,
    errors,
}: {
    capacityUnits: CapacityUnitOption[];
    sharing: string;
    capacity: string;
    capacityUnit: string;
    onCapacityChange: (value: string) => void;
    onCapacityUnitChange: (value: string) => void;
    errors: { capacity?: string; capacity_unit?: string };
}) {
    const unitLabel =
        capacityUnits.find((option) => option.value === capacityUnit)?.label ??
        capacityUnit;

    return (
        <>
            <ChoiceCards
                id="facility-capacity-unit"
                legend="Count by"
                choices={capacityUnits.map((option) => ({
                    value: option.value,
                    label: `${option.title} (${option.label})`,
                    description: option.description,
                    icon: CAPACITY_UNIT_ICONS[option.value] ?? User,
                }))}
                value={capacityUnit}
                onValueChange={onCapacityUnitChange}
                error={errors.capacity_unit}
            />

            <Field data-invalid={!!errors.capacity}>
                <FieldLabel htmlFor="facility-capacity">Capacity</FieldLabel>
                <InputGroup>
                    <InputGroupInput
                        id="facility-capacity"
                        type="number"
                        inputMode="numeric"
                        min={1}
                        placeholder="e.g. 150"
                        className="tabular-nums"
                        value={capacity}
                        onChange={(event) =>
                            onCapacityChange(event.target.value)
                        }
                        aria-invalid={!!errors.capacity}
                    />
                    <InputGroupAddon align="inline-end">
                        <InputGroupText>{unitLabel}</InputGroupText>
                    </InputGroupAddon>
                </InputGroup>
                <FieldDescription>
                    {sharing === 'shared'
                        ? 'Bookings stop when it is full.'
                        : 'Shown to staff. One booking takes it all.'}
                </FieldDescription>
                <FieldError>{errors.capacity}</FieldError>
            </Field>
        </>
    );
}

/**
 * The Details tab: every field of the facility and the taxes of its charge item, saved together. Available is the
 * switch in the page header.
 */
export function FacilityDetailsForm({
    facility,
    sharingOptions,
    capacityUnits,
    rateBases,
    taxes,
    currency,
}: {
    facility: Facility;
    sharingOptions: SharingOption[];
    capacityUnits: CapacityUnitOption[];
    rateBases: EnumOption[];
    taxes: Tax[];
    currency: App.Data.Property.CurrencyData | null;
}) {
    const form = useForm({
        name: facility.name,
        sharing: facility.sharing as string,
        capacity: String(facility.capacity),
        capacity_unit: facility.capacityUnit as string,
        charge_by_hand: facility.amount === null,
        default_amount: facility.amount ?? '',
        rate_basis: facility.rateBasis as string,
        tax_ids: facility.taxIds.map(String),
    });
    const { data, errors, setData } = form;
    const guard = useUnsavedChanges(form.isDirty);
    const losesSessions =
        facility.sessionCount > 0 && data.sharing === 'private';

    const save = (onSaved?: () => void) => {
        form.put(update.url({ facility: facility.id }), {
            preserveScroll: true,
            onSuccess: () => {
                form.setDefaults();
                onSaved?.();
            },
        });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        save();
    };

    return (
        <>
            <form onSubmit={submit} className="flex flex-col gap-5">
                <header className="flex flex-col gap-1">
                    <h3 className="text-base font-medium">Details</h3>
                    <p className="text-sm text-muted-foreground">
                        What staff book, how many it holds and what it costs.
                    </p>
                </header>

                <div className="grid gap-5 md:grid-cols-2">
                    <Field data-invalid={!!errors.name}>
                        <FieldLabel htmlFor="facility-name">Name</FieldLabel>
                        <Input
                            id="facility-name"
                            maxLength={100}
                            placeholder="e.g. Riverside Hall"
                            value={data.name}
                            onChange={(event) =>
                                setData('name', event.target.value)
                            }
                            aria-invalid={!!errors.name}
                        />
                        <FieldError>{errors.name}</FieldError>
                    </Field>

                    <div className="flex flex-col gap-2 md:col-span-2">
                        <ChoiceCards
                            id="facility-sharing"
                            legend="Sharing"
                            choices={sharingChoices(sharingOptions)}
                            value={data.sharing}
                            onValueChange={(value) => setData('sharing', value)}
                            error={errors.sharing}
                        />
                        {losesSessions && (
                            <FieldDescription>
                                Saving as Private deletes its{' '}
                                {facility.sessionCount === 1
                                    ? 'session'
                                    : `${facility.sessionCount} sessions`}
                                .
                            </FieldDescription>
                        )}
                    </div>

                    <div className="flex flex-col gap-5 md:col-span-2">
                        <CapacityFields
                            capacityUnits={capacityUnits}
                            sharing={data.sharing}
                            capacity={data.capacity}
                            capacityUnit={data.capacity_unit}
                            onCapacityChange={(value) =>
                                setData('capacity', value)
                            }
                            onCapacityUnitChange={(value) =>
                                setData('capacity_unit', value)
                            }
                            errors={errors}
                        />
                    </div>

                    <Field data-invalid={!!errors.default_amount}>
                        <FieldLabel htmlFor="facility-amount">Rate</FieldLabel>
                        <MoneyInput
                            id="facility-amount"
                            currency={currency}
                            placeholder="e.g. 1500"
                            value={
                                data.charge_by_hand ? '' : data.default_amount
                            }
                            onChange={(value) =>
                                setData('default_amount', value)
                            }
                            invalid={!!errors.default_amount}
                            disabled={data.charge_by_hand}
                        />
                        <FieldError>{errors.default_amount}</FieldError>
                    </Field>

                    <Field data-invalid={!!errors.rate_basis}>
                        <FieldLabel htmlFor="facility-rate-basis">
                            Per
                        </FieldLabel>
                        <EnumSelect
                            id="facility-rate-basis"
                            name="rate_basis"
                            placeholder="Choose a rate basis"
                            options={rateBases}
                            value={data.rate_basis}
                            onValueChange={(value) =>
                                setData('rate_basis', value)
                            }
                            aria-invalid={!!errors.rate_basis}
                        />
                        <FieldError>{errors.rate_basis}</FieldError>
                    </Field>

                    <Field
                        orientation="horizontal"
                        className="items-start justify-start md:col-span-2"
                    >
                        <Switch
                            id="facility-charge-by-hand"
                            checked={data.charge_by_hand}
                            onCheckedChange={(checked) =>
                                setData('charge_by_hand', checked)
                            }
                            className="mt-0.5"
                        />
                        <FieldContent className="flex-none">
                            <FieldLabel htmlFor="facility-charge-by-hand">
                                Charge by hand
                            </FieldLabel>
                            <FieldDescription>
                                Bookings post nothing. Staff post the charge
                                themselves.
                            </FieldDescription>
                        </FieldContent>
                    </Field>

                    {currency && (
                        <div className="md:col-span-2">
                            <ChargeItemTaxesField
                                taxes={taxes}
                                currency={currency}
                                value={data.tax_ids}
                                onValueChange={(value) =>
                                    setData('tax_ids', value)
                                }
                                error={errors.tax_ids}
                            />
                        </div>
                    )}
                </div>

                <footer className="flex justify-end">
                    <Button
                        type="submit"
                        disabled={form.processing}
                        focusableWhenDisabled
                    >
                        {form.processing && (
                            <Spinner data-icon="inline-start" />
                        )}
                        Save details
                    </Button>
                </footer>
            </form>

            <UnsavedChangesDialog
                guard={guard}
                name={facility.name}
                saving={form.processing}
                onSave={() => save(guard.proceed)}
            />
        </>
    );
}
