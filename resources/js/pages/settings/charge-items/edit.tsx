import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { ChargeItemChildrenField } from '@/components/pms/charge-item-children-field';
import { ChargeItemTaxesField } from '@/components/pms/charge-item-taxes-field';
import { EnumSelect } from '@/components/pms/enum-select';
import { FormActions } from '@/components/pms/form-actions';
import { PageHeader } from '@/components/pms/page-header';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import { cn } from '@/lib/utils';
import { edit, index, update } from '@/routes/settings/charge-items';

type ChargeItem = App.Data.Catalogues.ChargeItemData;
type Tax = App.Data.Catalogues.ChargeItemTaxData;
type ChildPrice = App.Data.Catalogues.ChargeItemChildPriceData;
type Option = { value: string; label: string };

const pricingOptions = [
    {
        value: 'fixed',
        label: 'Default amount',
        description:
            'The amount is filled in when staff post this item. Use it for items with a set price, such as breakfast or parking.',
    },
    {
        value: 'entered',
        label: 'Enter when charging',
        description:
            'Staff type the amount each time. Use it for items with a varying price, such as damage or minibar.',
    },
];

function Section({
    title,
    className,
    children,
}: {
    title: string;
    className?: string;
    children: React.ReactNode;
}) {
    return (
        <section className={cn('flex flex-col gap-4', className)}>
            <h2 className="text-base font-medium">{title}</h2>
            {children}
        </section>
    );
}

export default function ChargeItemEdit({
    chargeItem,
    autoAddLocked,
    childPrices,
    pricings,
    taxes,
    categories,
    chargingUnits,
    currency,
}: {
    chargeItem: ChargeItem;
    /** A boat departure uses this fare, so it is added by boat trips and never by the auto-add. */
    autoAddLocked: boolean;
    childPrices: ChildPrice[];
    pricings: Option[];
    taxes: Tax[];
    categories: Option[];
    chargingUnits: Option[];
    currency: App.Enums.Currency;
}) {
    const form = useForm({
        name: chargeItem.name,
        code: chargeItem.code,
        category: chargeItem.category as string,
        description: chargeItem.description ?? '',
        default_amount: chargeItem.defaultAmount ?? '',
        charging_unit: chargeItem.chargingUnit as string,
        allow_override: chargeItem.allowOverride,
        active: chargeItem.active,
        tax_ids: chargeItem.taxIds.map(String),
        auto_add: chargeItem.autoAdd,
        child_prices: childPrices.map((price) => ({
            property_age_group_id: price.ageGroupId,
            pricing: price.pricing as string,
            value: price.value ?? '',
        })),
    });
    const [enteredWhenCharging, setEnteredWhenCharging] = useState(
        chargeItem.defaultAmount === null,
    );
    const guard = useUnsavedChanges(form.isDirty);
    const isStarter = chargeItem.isStarter;

    const setPricing = (value: string) => {
        const entered = value === 'entered';

        setEnteredWhenCharging(entered);

        if (entered) {
            form.setData((data) => ({
                ...data,
                default_amount: '',
                allow_override: true,
                auto_add: false,
                child_prices: data.child_prices.map((price) =>
                    price.pricing === 'percent'
                        ? { ...price, pricing: 'free', value: '' }
                        : price,
                ),
            }));
        }
    };

    const usesChildPrices = ['per_guest', 'per_guest_night'].includes(
        form.data.charging_unit,
    );
    const errors = form.errors as Record<string, string | undefined>;
    const autoAddSwitch = (
        <Switch
            id="charge-item-auto-add"
            checked={form.data.auto_add}
            onCheckedChange={(checked) => form.setData('auto_add', checked)}
            disabled={enteredWhenCharging || autoAddLocked}
        />
    );

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

    const save = (onSaved?: () => void) => {
        form.put(update.url({ chargeItem: chargeItem.id }), {
            preserveScroll: true,
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
        <>
            <Head title={chargeItem.name} />

            <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
                <div
                    scroll-region=""
                    className="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto p-4"
                >
                    <PageHeader
                        title={chargeItem.name}
                        back={{ href: index.url(), label: 'Charge items' }}
                        description="Configure how staff select and price this charge."
                        badge={
                            <>
                                {!chargeItem.active && (
                                    <Badge variant="secondary">Inactive</Badge>
                                )}
                                {chargeItem.autoAdd && (
                                    <Badge variant="outline">Auto</Badge>
                                )}
                                {chargeItem.needsChildPriceReview && (
                                    <Badge variant="outline">
                                        Check child prices
                                    </Badge>
                                )}
                            </>
                        }
                    />

                    <div className="grid w-full max-w-5xl items-start gap-8 lg:grid-cols-2">
                        <Section title="Details">
                            <FieldGroup>
                                <Field data-invalid={!!form.errors.name}>
                                    <FieldLabel htmlFor="charge-item-name">
                                        Name
                                    </FieldLabel>
                                    <Input
                                        id="charge-item-name"
                                        maxLength={100}
                                        value={form.data.name}
                                        onChange={(event) =>
                                            form.setData(
                                                'name',
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={!!form.errors.name}
                                    />
                                    <FieldError>{form.errors.name}</FieldError>
                                </Field>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field data-invalid={!!form.errors.code}>
                                        <FieldLabel htmlFor="charge-item-code">
                                            Code
                                        </FieldLabel>
                                        <Input
                                            id="charge-item-code"
                                            maxLength={20}
                                            value={form.data.code}
                                            onChange={(event) =>
                                                form.setData(
                                                    'code',
                                                    event.target.value.toUpperCase(),
                                                )
                                            }
                                            disabled={isStarter}
                                            aria-invalid={!!form.errors.code}
                                        />
                                        <FieldDescription>
                                            {isStarter
                                                ? 'Starter items keep their code.'
                                                : 'Used in reports and accounting exports.'}
                                        </FieldDescription>
                                        <FieldError>
                                            {form.errors.code}
                                        </FieldError>
                                    </Field>
                                    <Field
                                        data-invalid={!!form.errors.category}
                                    >
                                        <FieldLabel htmlFor="charge-item-category">
                                            Category
                                        </FieldLabel>
                                        <EnumSelect
                                            id="charge-item-category"
                                            name="category"
                                            options={categories}
                                            value={form.data.category}
                                            onValueChange={(value) =>
                                                form.setData('category', value)
                                            }
                                            disabled={isStarter}
                                            aria-invalid={
                                                !!form.errors.category
                                            }
                                        />
                                        <FieldError>
                                            {form.errors.category}
                                        </FieldError>
                                    </Field>
                                </div>
                                <Field data-invalid={!!form.errors.description}>
                                    <FieldLabel htmlFor="charge-item-description">
                                        Description
                                    </FieldLabel>
                                    <Textarea
                                        id="charge-item-description"
                                        placeholder="e.g. One-way transfer to the airport, up to 4 guests"
                                        maxLength={2000}
                                        value={form.data.description}
                                        onChange={(event) =>
                                            form.setData(
                                                'description',
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={!!form.errors.description}
                                    />
                                    <FieldDescription>
                                        Optional guidance shown to staff when
                                        choosing a charge.
                                    </FieldDescription>
                                    <FieldError>
                                        {form.errors.description}
                                    </FieldError>
                                </Field>
                            </FieldGroup>
                        </Section>

                        <Section title="Pricing">
                            <FieldGroup>
                                <Field
                                    data-invalid={!!form.errors.charging_unit}
                                >
                                    <FieldLabel htmlFor="charge-item-unit">
                                        Charged
                                    </FieldLabel>
                                    <EnumSelect
                                        id="charge-item-unit"
                                        name="charging_unit"
                                        options={chargingUnits}
                                        value={form.data.charging_unit}
                                        onValueChange={(value) =>
                                            form.setData('charging_unit', value)
                                        }
                                        aria-invalid={
                                            !!form.errors.charging_unit
                                        }
                                    />
                                    <FieldError>
                                        {form.errors.charging_unit}
                                    </FieldError>
                                </Field>
                                <FieldSet>
                                    <FieldLegend variant="label">
                                        Pricing method
                                    </FieldLegend>
                                    <RadioGroup
                                        value={
                                            enteredWhenCharging
                                                ? 'entered'
                                                : 'fixed'
                                        }
                                        onValueChange={(value) =>
                                            setPricing(value as string)
                                        }
                                    >
                                        {pricingOptions.map((option) => (
                                            <FieldLabel
                                                key={option.value}
                                                htmlFor={`charge-item-price-${option.value}`}
                                            >
                                                <Field orientation="horizontal">
                                                    <RadioGroupItem
                                                        id={`charge-item-price-${option.value}`}
                                                        value={option.value}
                                                    />
                                                    <FieldContent>
                                                        <span className="text-sm font-medium">
                                                            {option.label}
                                                        </span>
                                                        <FieldDescription>
                                                            {option.description}
                                                        </FieldDescription>
                                                    </FieldContent>
                                                </Field>
                                            </FieldLabel>
                                        ))}
                                    </RadioGroup>
                                </FieldSet>
                                {!enteredWhenCharging && (
                                    <Field
                                        data-invalid={
                                            !!form.errors.default_amount
                                        }
                                    >
                                        <FieldLabel htmlFor="charge-item-amount">
                                            Price
                                        </FieldLabel>
                                        <InputGroup className="sm:max-w-60">
                                            <InputGroupAddon>
                                                {currency}
                                            </InputGroupAddon>
                                            <InputGroupInput
                                                id="charge-item-amount"
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
                                                aria-invalid={
                                                    !!form.errors.default_amount
                                                }
                                            />
                                        </InputGroup>
                                        <FieldError>
                                            {form.errors.default_amount}
                                        </FieldError>
                                    </Field>
                                )}
                                <Field orientation="horizontal">
                                    <Switch
                                        id="charge-item-override"
                                        checked={form.data.allow_override}
                                        onCheckedChange={(checked) =>
                                            form.setData(
                                                'allow_override',
                                                checked,
                                            )
                                        }
                                        disabled={enteredWhenCharging}
                                    />
                                    <FieldContent>
                                        <FieldLabel htmlFor="charge-item-override">
                                            Allow amount override
                                        </FieldLabel>
                                        <FieldDescription>
                                            Staff may change the default amount
                                            when posting the charge.
                                        </FieldDescription>
                                    </FieldContent>
                                </Field>
                            </FieldGroup>
                        </Section>

                        {usesChildPrices && (
                            <Section title="Children" className="lg:col-span-2">
                                <ChargeItemChildrenField
                                    groups={childPrices}
                                    rows={form.data.child_prices}
                                    pricings={pricings}
                                    currency={currency}
                                    errors={errors}
                                    enteredWhenCharging={enteredWhenCharging}
                                    onChange={setChildPrice}
                                />
                            </Section>
                        )}

                        <Section title="New bookings" className="lg:col-span-2">
                            <Field
                                orientation="horizontal"
                                data-invalid={!!form.errors.auto_add}
                            >
                                {autoAddLocked ? (
                                    <Tooltip>
                                        <TooltipTrigger
                                            render={
                                                <span className="inline-flex" />
                                            }
                                        >
                                            {autoAddSwitch}
                                        </TooltipTrigger>
                                        <TooltipContent>
                                            Added by boat trips
                                        </TooltipContent>
                                    </Tooltip>
                                ) : (
                                    autoAddSwitch
                                )}
                                <FieldContent>
                                    <FieldLabel htmlFor="charge-item-auto-add">
                                        Add to every new booking
                                    </FieldLabel>
                                    <FieldDescription>
                                        {autoAddLocked
                                            ? 'Added by boat trips. A boat trip adds this fare, so it is never charged twice.'
                                            : enteredWhenCharging
                                              ? 'Set a fixed price first.'
                                              : 'Put this item on every new room as an add-on, so staff do not have to remember it. Existing bookings do not change.'}
                                    </FieldDescription>
                                    <FieldError>
                                        {form.errors.auto_add}
                                    </FieldError>
                                </FieldContent>
                            </Field>
                        </Section>

                        <Section title="Taxes" className="lg:col-span-2">
                            <ChargeItemTaxesField
                                taxes={taxes}
                                currency={currency}
                                value={form.data.tax_ids}
                                onValueChange={(value) =>
                                    form.setData('tax_ids', value)
                                }
                                error={form.errors.tax_ids}
                            />
                        </Section>
                    </div>
                </div>

                <FormActions fixed>
                    <Link
                        href={index()}
                        className={buttonVariants({ variant: 'outline' })}
                    >
                        Cancel
                    </Link>
                    <Button
                        type="submit"
                        disabled={form.processing}
                        focusableWhenDisabled
                    >
                        {form.processing && (
                            <Spinner data-icon="inline-start" />
                        )}
                        Save charge item
                    </Button>
                </FormActions>
            </form>

            <UnsavedChangesDialog
                guard={guard}
                name={chargeItem.name}
                saving={form.processing}
                onSave={() => save(guard.proceed)}
            />
        </>
    );
}

ChargeItemEdit.layout = (props: { chargeItem: ChargeItem }) => ({
    variant: 'fixed' as const,
    breadcrumbs: [
        { title: 'Charge items', href: index() },
        {
            title: props.chargeItem.name,
            href: edit({ chargeItem: props.chargeItem.id }),
        },
    ],
});
