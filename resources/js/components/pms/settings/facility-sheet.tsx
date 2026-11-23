import { useForm } from '@inertiajs/react';
import { Lock, Users } from 'lucide-react';
import { ChoiceCards } from '@/components/pms/choice-cards';
import type { Choice } from '@/components/pms/choice-cards';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyInput } from '@/components/pms/money-input';
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
import { store, update } from '@/routes/settings/facilities';

type Facility = App.Data.Facilities.FacilityData;
type SharingOption = { value: string; label: string; description: string };

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The facility being edited, or null to add one. */
    facility: Facility | null;
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

const SHARING_ICONS: Record<string, Choice['icon']> = {
    private: Lock,
    shared: Users,
};

/** The full row, so Turn off and Turn on can save it with a new `active`. */
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
    facility,
    sharingOptions,
    capacityUnits,
    rateBases,
    currency,
}: Props) {
    const form = useForm<FormData>(
        facility === null
            ? {
                  name: '',
                  sharing: 'private',
                  capacity: '',
                  capacity_unit: 'pax',
                  charge_by_hand: false,
                  default_amount: '',
                  rate_basis: '',
                  active: true,
              }
            : facilityValues(facility),
    );
    const { data, errors, setData } = form;
    const losesSessions =
        facility !== null &&
        facility.sessionCount > 0 &&
        data.sharing === 'private';

    const submit = () => {
        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        };

        if (facility === null) {
            form.post(store.url(), options);
        } else {
            form.put(update.url({ facility: facility.id }), options);
        }
    };

    return (
        <FormOverlay
            variant="sheet"
            open={open}
            onOpenChange={onOpenChange}
            title={facility === null ? 'Add facility' : `Edit ${facility.name}`}
            description="A hall, piece of equipment or activity staff can book."
            errors={errors}
            dirty={form.isDirty}
            discardTitle="Discard this facility?"
            onSubmit={submit}
            submitLabel={facility === null ? 'Add facility' : 'Save facility'}
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
                    choices={sharingOptions.map((option) => ({
                        ...option,
                        icon: SHARING_ICONS[option.value] ?? Lock,
                    }))}
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
                            Active
                        </FieldLabel>
                        <FieldDescription>
                            A facility that is off keeps its bookings but takes
                            no new ones.
                        </FieldDescription>
                    </FieldContent>
                </Field>
            </FieldGroup>
        </FormOverlay>
    );
}

/**
 * Adds or edits one facility. Keyed on each opening, so it always starts from the facility it was opened for.
 */
export function FacilitySheet(props: Props) {
    return <FacilityForm key={useFreshOnOpen(props.open)} {...props} />;
}
