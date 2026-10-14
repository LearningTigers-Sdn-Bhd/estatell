import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { ChargeItemChildrenField } from '@/components/pms/charge-item-children-field';
import { ChargeItemTaxesField } from '@/components/pms/charge-item-taxes-field';
import { FormOverlay } from '@/components/pms/form-overlay';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Checkbox } from '@/components/ui/checkbox';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import { Input } from '@/components/ui/input';
import { suggestCode } from '@/lib/charge-code';
import { store, update } from '@/routes/settings/boats/fares';

type Fare = App.Data.Boats.BoatFareRowData;
type AgeGroup = App.Data.Boats.BoatFareAgeGroupData;
type Tax = App.Data.Catalogues.ChargeItemTaxData;
type Option = { value: string; label: string };

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The fare being edited, or null to add one. */
    fare: Fare | null;
    ageGroups: AgeGroup[];
    taxes: Tax[];
    pricings: Option[];
    currency: App.Data.Property.CurrencyData;
};

type ChildPriceValues = {
    property_age_group_id: number;
    pricing: string;
    value: string;
};

type FormData = {
    name: string;
    code: string;
    default_amount: string;
    allow_override: boolean;
    tax_ids: string[];
    child_prices: ChildPriceValues[];
};

function toFormData(fare: Fare | null, ageGroups: AgeGroup[]): FormData {
    if (fare === null) {
        return {
            name: '',
            code: '',
            default_amount: '',
            allow_override: true,
            tax_ids: [],
            // A new fare starts as a percentage of its adult price, the default of a priced charge item.
            child_prices: ageGroups.map((group) => ({
                property_age_group_id: group.ageGroupId,
                pricing: 'percent',
                value: '100',
            })),
        };
    }

    return {
        name: fare.name,
        code: fare.code,
        default_amount: fare.amount ?? '',
        allow_override: fare.allowOverride,
        tax_ids: fare.taxes.map((tax) => String(tax.id)),
        child_prices: fare.childPrices.map((price) => ({
            property_age_group_id: price.ageGroupId,
            pricing: price.pricing as string,
            value: price.value ?? '',
        })),
    };
}

function Section({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section className="flex flex-col gap-4">
            <h3 className="text-sm font-medium">{title}</h3>
            {children}
        </section>
    );
}

function BoatFareForm({
    open,
    onOpenChange,
    fare,
    ageGroups,
    taxes,
    pricings,
    currency,
}: Props) {
    const form = useForm<FormData>(toFormData(fare, ageGroups));
    const errors = form.errors as Record<string, string | undefined>;
    const [codeEdited, setCodeEdited] = useState(false);
    const isNew = fare === null;
    const groups = fare ? fare.childPrices : ageGroups;

    const setName = (name: string) => {
        form.setData((data) => ({
            ...data,
            name,
            code: isNew && !codeEdited ? suggestCode(name) : data.code,
        }));
    };

    const setChildPrice = (
        index: number,
        values: { pricing?: string; value?: string },
    ) => {
        form.setData(
            'child_prices',
            form.data.child_prices.map((price, position) =>
                position === index ? { ...price, ...values } : price,
            ),
        );
    };

    const submit = () => {
        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        };

        if (fare) {
            form.put(update.url({ chargeItem: fare.id }), options);
        } else {
            form.post(store.url(), options);
        }
    };

    return (
        <FormOverlay
            variant="sheet"
            size="lg"
            open={open}
            onOpenChange={onOpenChange}
            title={fare ? `Edit fare · ${fare.name}` : 'Add fare'}
            description="The one-way price per guest for a boat."
            errors={form.errors}
            dirty={form.isDirty}
            discardTitle="Discard this fare?"
            onSubmit={submit}
            submitLabel={fare ? 'Save fare' : 'Add fare'}
            processing={form.processing}
        >
            <FieldGroup>
                <Section title="Details">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field data-invalid={!!errors.name}>
                            <FieldLabel htmlFor="boat-fare-name">
                                Name
                            </FieldLabel>
                            <Input
                                id="boat-fare-name"
                                maxLength={100}
                                value={form.data.name}
                                onChange={(event) =>
                                    setName(event.target.value)
                                }
                                aria-invalid={!!errors.name}
                            />
                            <FieldError>{errors.name}</FieldError>
                        </Field>
                        <Field data-invalid={!!errors.code}>
                            <FieldLabel htmlFor="boat-fare-code">
                                Code
                            </FieldLabel>
                            <Input
                                id="boat-fare-code"
                                maxLength={20}
                                value={form.data.code}
                                onChange={(event) => {
                                    setCodeEdited(true);
                                    form.setData(
                                        'code',
                                        event.target.value.toUpperCase(),
                                    );
                                }}
                                disabled={!isNew}
                                aria-invalid={!!errors.code}
                            />
                            <FieldDescription>
                                {isNew
                                    ? 'Used in reports and accounting exports.'
                                    : 'Set when the fare is added. Change it on the charge item page.'}
                            </FieldDescription>
                            <FieldError>{errors.code}</FieldError>
                        </Field>
                    </div>
                </Section>

                <Section title="Price · one way, per guest">
                    <Field data-invalid={!!errors.default_amount}>
                        <FieldLabel htmlFor="boat-fare-amount">
                            Adult
                        </FieldLabel>
                        <InputGroup className="sm:max-w-60">
                            <InputGroupAddon>{currency.code}</InputGroupAddon>
                            <InputGroupInput
                                id="boat-fare-amount"
                                placeholder="0.00"
                                type="number"
                                min="0.01"
                                max="9999999999.99"
                                step="0.01"
                                inputMode="decimal"
                                value={form.data.default_amount}
                                onChange={(event) =>
                                    form.setData(
                                        'default_amount',
                                        event.target.value,
                                    )
                                }
                                aria-invalid={!!errors.default_amount}
                            />
                        </InputGroup>
                        <FieldError>{errors.default_amount}</FieldError>
                    </Field>
                    <Field orientation="horizontal">
                        <Checkbox
                            id="boat-fare-override"
                            checked={form.data.allow_override}
                            onCheckedChange={(checked) =>
                                form.setData('allow_override', checked)
                            }
                        />
                        <FieldContent>
                            <FieldLabel htmlFor="boat-fare-override">
                                Allow another amount when charging
                            </FieldLabel>
                        </FieldContent>
                    </Field>
                </Section>

                <Section title="Children">
                    <ChargeItemChildrenField
                        groups={groups}
                        rows={form.data.child_prices}
                        pricings={pricings}
                        currency={currency}
                        errors={errors}
                        enteredWhenCharging={false}
                        onChange={setChildPrice}
                    />
                </Section>

                <Section title="Taxes">
                    <ChargeItemTaxesField
                        taxes={taxes}
                        currency={currency}
                        value={form.data.tax_ids}
                        onValueChange={(value) =>
                            form.setData('tax_ids', value)
                        }
                        error={errors.tax_ids}
                    />
                </Section>

                <FieldDescription>
                    New prices apply to new boat trips. Booked trips keep their
                    price.
                </FieldDescription>
            </FieldGroup>
        </FormOverlay>
    );
}

/**
 * Adds or edits one fare. Keyed on each opening, so it always starts from the fare it was opened for.
 */
export function BoatFareSheet(props: Props) {
    return <BoatFareForm key={useFreshOnOpen(props.open)} {...props} />;
}
