import { useForm } from '@inertiajs/react';
import { ChoiceCards } from '@/components/pms/choice-cards';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyInput } from '@/components/pms/money-input';
import {
    sharingChoices,
    type SharingOption,
} from '@/components/pms/settings/facility-details-form';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { store } from '@/routes/settings/facilities';

type Facility = App.Data.Facilities.FacilityData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    sharingOptions: SharingOption[];
    capacityUnits: EnumOption[];
    rateBases: EnumOption[];
    currency: App.Data.Property.CurrencyData | null;
};

type FormData = {
    name: string;
    sharing: string;
    capacity: string;
    capacity_unit: string;
    charge_by_hand: boolean;
    default_amount: string;
    rate_basis: string;
    active: boolean;
};

/** The full row, so an Available switch can save it with a new `active`. Taxes are left out, so they stay. */
export function facilityValues(facility: Facility): FormData {
    return {
        name: facility.name,
        sharing: facility.sharing,
        capacity: String(facility.capacity),
        capacity_unit: facility.capacityUnit,
        charge_by_hand: facility.amount === null,
        default_amount: facility.amount ?? '',
        rate_basis: facility.rateBasis,
        active: facility.active,
    };
}

function FacilityForm({
    open,
    onOpenChange,
    sharingOptions,
    capacityUnits,
    rateBases,
    currency,
}: Props) {
    const form = useForm<FormData>({
        name: '',
        sharing: 'private',
        capacity: '',
        capacity_unit: 'pax',
        charge_by_hand: false,
        default_amount: '',
        rate_basis: '',
        active: true,
    });
    const { data, errors, setData } = form;

    return (
        <FormOverlay
            variant="sheet"
            open={open}
            onOpenChange={onOpenChange}
            title="Add facility"
            description="A hall, piece of equipment or activity staff can book. Add taxes and sessions on its page next."
            errors={errors}
            dirty={form.isDirty}
            discardTitle="Discard this facility?"
            onSubmit={() => form.post(store.url())}
            submitLabel="Add facility"
            processing={form.processing}
        >
            <FieldGroup>
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
                        autoFocus
                    />
                    <FieldError>{errors.name}</FieldError>
                </Field>

                <ChoiceCards
                    id="facility-sharing"
                    legend="Sharing"
                    choices={sharingChoices(sharingOptions)}
                    value={data.sharing}
                    onValueChange={(value) => setData('sharing', value)}
                    error={errors.sharing}
                />

                <Field data-invalid={!!errors.capacity}>
                    <FieldLabel htmlFor="facility-capacity">
                        Capacity
                    </FieldLabel>
                    <div className="flex gap-2">
                        <Input
                            id="facility-capacity"
                            type="number"
                            inputMode="numeric"
                            min={1}
                            placeholder="e.g. 150"
                            className="min-w-0 flex-1"
                            value={data.capacity}
                            onChange={(event) =>
                                setData('capacity', event.target.value)
                            }
                            aria-invalid={!!errors.capacity}
                        />
                        <EnumSelect
                            id="facility-capacity-unit"
                            name="capacity_unit"
                            aria-label="Capacity unit"
                            className="w-32"
                            options={capacityUnits}
                            value={data.capacity_unit}
                            onValueChange={(value) =>
                                setData('capacity_unit', value)
                            }
                            aria-invalid={!!errors.capacity_unit}
                        />
                    </div>
                    <FieldDescription>
                        {data.sharing === 'shared'
                            ? 'Bookings stop when it is full.'
                            : 'Shown to staff. One booking takes it all.'}
                    </FieldDescription>
                    <FieldError>
                        {errors.capacity ?? errors.capacity_unit}
                    </FieldError>
                </Field>

                <Field data-invalid={!!errors.default_amount}>
                    <FieldLabel htmlFor="facility-amount">Rate</FieldLabel>
                    <MoneyInput
                        id="facility-amount"
                        currency={currency}
                        placeholder="e.g. 1500"
                        value={data.charge_by_hand ? '' : data.default_amount}
                        onChange={(value) => setData('default_amount', value)}
                        invalid={!!errors.default_amount}
                        disabled={data.charge_by_hand}
                    />
                    <FieldError>{errors.default_amount}</FieldError>
                </Field>
                <Field orientation="horizontal">
                    <Switch
                        id="facility-charge-by-hand"
                        checked={data.charge_by_hand}
                        onCheckedChange={(checked) =>
                            setData('charge_by_hand', checked)
                        }
                    />
                    <FieldContent>
                        <FieldLabel htmlFor="facility-charge-by-hand">
                            Charge by hand
                        </FieldLabel>
                        <FieldDescription>
                            Bookings post nothing. Staff post the charge
                            themselves.
                        </FieldDescription>
                    </FieldContent>
                </Field>

                <Field data-invalid={!!errors.rate_basis}>
                    <FieldLabel htmlFor="facility-rate-basis">Per</FieldLabel>
                    <EnumSelect
                        id="facility-rate-basis"
                        name="rate_basis"
                        placeholder="Choose a rate basis"
                        options={rateBases}
                        value={data.rate_basis}
                        onValueChange={(value) => setData('rate_basis', value)}
                        aria-invalid={!!errors.rate_basis}
                    />
                    <FieldError>{errors.rate_basis}</FieldError>
                </Field>

                <Field orientation="horizontal">
                    <Switch
                        id="facility-active"
                        checked={data.active}
                        onCheckedChange={(checked) =>
                            setData('active', checked)
                        }
                    />
                    <FieldContent>
                        <FieldLabel htmlFor="facility-active">
                            Available
                        </FieldLabel>
                        <FieldDescription>
                            An unavailable facility keeps its bookings but takes
                            no new ones.
                        </FieldDescription>
                    </FieldContent>
                </Field>
            </FieldGroup>
        </FormOverlay>
    );
}

/**
 * Adds a facility. Saving opens the new facility's page. Keyed on each opening, so it always starts clean.
 */
export function FacilitySheet(props: Props) {
    return <FacilityForm key={useFreshOnOpen(props.open)} {...props} />;
}
