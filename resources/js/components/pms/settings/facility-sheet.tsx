import { useForm } from '@inertiajs/react';
import { EnumSelect } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyInput } from '@/components/pms/money-input';
import {
    BookedByFields,
    type BookedByOption,
    type RateBasesByBookedBy,
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
    bookedByOptions: BookedByOption[];
    rateBases: RateBasesByBookedBy;
    currency: App.Data.Property.CurrencyData | null;
};

type FormData = {
    name: string;
    booked_by: string;
    capacity: string;
    charge_by_hand: boolean;
    default_amount: string;
    rate_basis: string;
    active: boolean;
};

/** The full row, so an Available switch can save it with a new `active`. Taxes are left out, so they stay. */
export function facilityValues(facility: Facility): FormData {
    return {
        name: facility.name,
        booked_by: facility.bookedBy,
        capacity: String(facility.capacity),
        charge_by_hand: facility.amount === null,
        default_amount: facility.amount ?? '',
        rate_basis: facility.rateBasis,
        active: facility.active,
    };
}

function FacilityForm({
    open,
    onOpenChange,
    bookedByOptions,
    rateBases,
    currency,
}: Props) {
    const form = useForm<FormData>({
        name: '',
        booked_by: 'item',
        capacity: '',
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

                <BookedByFields
                    bookedByOptions={bookedByOptions}
                    bookedBy={data.booked_by}
                    capacity={data.capacity}
                    onBookedByChange={(value) => setData('booked_by', value)}
                    onCapacityChange={(value) => setData('capacity', value)}
                    errors={errors}
                />

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
                        options={rateBases[data.booked_by] ?? []}
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
