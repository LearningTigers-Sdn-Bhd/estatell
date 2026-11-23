import { useForm } from '@inertiajs/react';
import { Package, User } from 'lucide-react';
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
export type BookedByOption = {
    value: string;
    label: string;
    description: string;
    unit: string;
};

/** The Per select for each Booked by choice: Each reads "Per item" or "Per person". */
export type RateBasesByBookedBy = Record<string, EnumOption[]>;

const BOOKED_BY_ICONS: Record<string, Choice['icon']> = {
    item: Package,
    person: User,
};

/**
 * Booked by (Item or Person) as choice cards, then How many with "items" or "people" after the number. Shared by the
 * Add facility Sheet and the Details tab.
 */
export function BookedByFields({
    bookedByOptions,
    bookedBy,
    capacity,
    onBookedByChange,
    onCapacityChange,
    errors,
}: {
    bookedByOptions: BookedByOption[];
    bookedBy: string;
    capacity: string;
    onBookedByChange: (value: string) => void;
    onCapacityChange: (value: string) => void;
    errors: { booked_by?: string; capacity?: string };
}) {
    const unit =
        bookedByOptions.find((option) => option.value === bookedBy)?.unit ?? '';

    return (
        <>
            <ChoiceCards
                id="facility-booked-by"
                legend="Booked by"
                choices={bookedByOptions.map((option) => ({
                    value: option.value,
                    label: option.label,
                    description: option.description,
                    icon: BOOKED_BY_ICONS[option.value] ?? Package,
                }))}
                value={bookedBy}
                onValueChange={onBookedByChange}
                error={errors.booked_by}
            />

            <Field data-invalid={!!errors.capacity}>
                <FieldLabel htmlFor="facility-capacity">How many</FieldLabel>
                <InputGroup>
                    <InputGroupInput
                        id="facility-capacity"
                        type="number"
                        inputMode="numeric"
                        min={1}
                        placeholder={
                            bookedBy === 'person' ? 'e.g. 25' : 'e.g. 3'
                        }
                        className="tabular-nums"
                        value={capacity}
                        onChange={(event) =>
                            onCapacityChange(event.target.value)
                        }
                        aria-invalid={!!errors.capacity}
                    />
                    <InputGroupAddon align="inline-end">
                        <InputGroupText>{unit}</InputGroupText>
                    </InputGroupAddon>
                </InputGroup>
                <FieldDescription>
                    {bookedBy === 'person'
                        ? 'People at one time. Bookings stop when it is full.'
                        : 'How many you have. 1 means one group at a time.'}
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
    bookedByOptions,
    rateBases,
    taxes,
    currency,
}: {
    facility: Facility;
    bookedByOptions: BookedByOption[];
    rateBases: RateBasesByBookedBy;
    taxes: Tax[];
    currency: App.Data.Property.CurrencyData | null;
}) {
    const form = useForm({
        name: facility.name,
        booked_by: facility.bookedBy as string,
        capacity: String(facility.capacity),
        charge_by_hand: facility.amount === null,
        default_amount: facility.amount ?? '',
        rate_basis: facility.rateBasis as string,
        private_amount: facility.privateAmount ?? '',
        tax_ids: facility.taxIds.map(String),
    });
    const { data, errors, setData } = form;
    const guard = useUnsavedChanges(form.isDirty);

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

                    <div className="flex flex-col gap-5 md:col-span-2">
                        <BookedByFields
                            bookedByOptions={bookedByOptions}
                            bookedBy={data.booked_by}
                            capacity={data.capacity}
                            onBookedByChange={(value) =>
                                setData('booked_by', value)
                            }
                            onCapacityChange={(value) =>
                                setData('capacity', value)
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
                            options={rateBases[data.booked_by] ?? []}
                            value={data.rate_basis}
                            onValueChange={(value) =>
                                setData('rate_basis', value)
                            }
                            aria-invalid={!!errors.rate_basis}
                        />
                        <FieldError>{errors.rate_basis}</FieldError>
                    </Field>

                    {data.booked_by === 'person' && (
                        <Field
                            className="md:col-span-2"
                            data-invalid={!!errors.private_amount}
                        >
                            <FieldLabel htmlFor="facility-private-amount">
                                Private price
                            </FieldLabel>
                            <MoneyInput
                                id="facility-private-amount"
                                currency={currency}
                                placeholder="e.g. 700"
                                value={data.private_amount}
                                onChange={(value) =>
                                    setData('private_amount', value)
                                }
                                invalid={!!errors.private_amount}
                            />
                            <FieldDescription>
                                One price for a private booking, such as a
                                private boat. It takes every place at that time.
                                Leave empty if it cannot be booked privately.
                            </FieldDescription>
                            <FieldError>{errors.private_amount}</FieldError>
                        </Field>
                    )}

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
