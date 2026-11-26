import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyInput } from '@/components/pms/money-input';
import { MultiCombobox } from '@/components/pms/multi-combobox';
import type { MultiComboboxOption } from '@/components/pms/multi-combobox';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import { Switch } from '@/components/ui/switch';
import {
    store as storeGroup,
    update as updateGroup,
} from '@/routes/settings/pos/modifier-groups';
import { unlessRefused } from './pos-unless-refused';

export type ModifierOptionRow = {
    id: number | null;
    name: string;
    priceDelta: string;
    active: boolean;
    availability: string;
};

export type ModifierGroupRow = {
    id: number;
    name: string;
    minSelect: number;
    maxSelect: number | null;
    maxPerOption: number;
    freeIncludedCount: number;
    active: boolean;
    itemIds: number[];
    options: ModifierOptionRow[];
};

const SELECTION_PRESETS = [
    {
        id: 'optional',
        label: 'Optional add-ons',
        min: '0',
        max: '',
        perOption: '1',
    },
    {
        id: 'required-one',
        label: 'Required: pick 1',
        min: '1',
        max: '1',
        perOption: '1',
    },
    {
        id: 'required-multi',
        label: 'Required: pick up to 3',
        min: '1',
        max: '3',
        perOption: '1',
    },
] as const;

function describeSelectionRules(data: {
    min_select: string;
    max_select: string;
    max_per_option: string;
    free_included_count: string;
}): string {
    const min = Math.max(0, Number(data.min_select) || 0);
    const max =
        data.max_select.trim() === ''
            ? null
            : Math.max(1, Number(data.max_select) || 1);
    const perOption = Math.max(1, Number(data.max_per_option) || 1);
    const freeCount = Math.max(0, Number(data.free_included_count) || 0);

    const sentences: string[] = [];

    if (min === 0 && max === null) {
        sentences.push('Cashiers can pick any number of choices (optional).');
    } else if (min === 0 && max !== null) {
        sentences.push(
            `Optional: cashiers can pick up to ${max} ${max === 1 ? 'choice' : 'choices'}.`,
        );
    } else if (max !== null && min === max) {
        sentences.push(
            `Required: cashiers must pick exactly ${min} ${min === 1 ? 'choice' : 'choices'}.`,
        );
    } else if (max !== null) {
        sentences.push(
            `Required: cashiers must pick ${min} to ${max} choices.`,
        );
    } else {
        sentences.push(
            `Required: cashiers must pick at least ${min} ${min === 1 ? 'choice' : 'choices'}.`,
        );
    }

    if (perOption > 1) {
        sentences.push(`Each choice can be selected up to ${perOption} times.`);
    }

    if (freeCount > 0) {
        sentences.push(
            `First ${freeCount} paid ${freeCount === 1 ? 'choice is' : 'choices are'} included free.`,
        );
    }

    return sentences.join(' ');
}

export function PosModifierGroupForm({
    row,
    currency,
    items,
    onClose,
}: {
    row: ModifierGroupRow | null;
    currency: App.Data.Property.CurrencyData | null;
    items: MultiComboboxOption[];
    onClose: () => void;
}) {
    const form = useForm({
        name: row?.name ?? '',
        min_select: String(row?.minSelect ?? 0),
        max_select: String(row?.maxSelect ?? ''),
        max_per_option: String(row?.maxPerOption ?? 1),
        free_included_count: String(row?.freeIncludedCount ?? 0),
        active: row?.active ?? true,
        item_ids: (row?.itemIds ?? []).map(String),
        options: row?.options ?? [
            {
                id: null,
                name: '',
                priceDelta: '0.00',
                active: true,
                availability: 'all',
            },
        ],
    });

    function patch(index: number, change: Partial<ModifierOptionRow>) {
        form.setData(
            'options',
            form.data.options.map((option, at) =>
                at === index ? { ...option, ...change } : option,
            ),
        );
    }

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <FormOverlay
            variant="sheet"
            size="lg"
            open
            onOpenChange={(value) => !value && onClose()}
            title={row ? 'Edit modifier group' : 'Add modifier group'}
            description="Create add-ons or preparation choices that cashiers pick when ordering linked menu items."
            errors={form.errors}
            dirty={form.isDirty}
            discardTitle={
                row
                    ? 'Discard changes to this modifier group?'
                    : 'Discard this new modifier group?'
            }
            processing={form.processing}
            submitLabel={row ? 'Save group' : 'Create group'}
            onSubmit={() => {
                form.transform((data) => ({
                    ...data,
                    max_select: data.max_select === '' ? null : data.max_select,
                    options: data.options.map((option) => ({
                        id: option.id,
                        name: option.name,
                        price_delta: option.priceDelta,
                        active: option.active,
                    })),
                }));
                const options = {
                    preserveScroll: true,
                    onSuccess: unlessRefused(onClose),
                };

                if (row) {
                    form.put(updateGroup.url({ group: row.id }), options);
                } else {
                    form.post(storeGroup.url(), options);
                }
            }}
        >
            <FieldSet>
                <FieldLegend>Group details</FieldLegend>
                <Field data-invalid={!!form.errors.name}>
                    <FieldLabel htmlFor="group-name">Group name</FieldLabel>
                    <Input
                        id="group-name"
                        maxLength={100}
                        placeholder="e.g. Milk choice, Cooking temperature, Extra toppings"
                        required
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        aria-invalid={!!form.errors.name}
                        autoFocus
                    />
                    <FieldError>{form.errors.name}</FieldError>
                </Field>
                <Field
                    orientation="horizontal"
                    className="items-start rounded-lg border bg-muted/20 p-3"
                >
                    <Switch
                        id="group-active"
                        checked={form.data.active}
                        onCheckedChange={(checked) =>
                            form.setData('active', checked)
                        }
                        className="mt-0.5"
                    />
                    <FieldContent>
                        <FieldLabel htmlFor="group-active">
                            Active on register
                        </FieldLabel>
                        <FieldDescription>
                            Turn off to pause this modifier group across all
                            linked items.
                        </FieldDescription>
                    </FieldContent>
                </Field>
            </FieldSet>

            <Separator />

            <FieldSet>
                <FieldLegend>Selection rules</FieldLegend>
                <FieldDescription>
                    Pick a common rule preset or customize the exact selection
                    limits below.
                </FieldDescription>

                <div className="flex flex-wrap gap-2">
                    {SELECTION_PRESETS.map((preset) => {
                        const matched =
                            form.data.min_select === preset.min &&
                            form.data.max_select === preset.max &&
                            form.data.max_per_option === preset.perOption;

                        return (
                            <Button
                                key={preset.id}
                                type="button"
                                size="sm"
                                variant={matched ? 'default' : 'outline'}
                                aria-pressed={matched}
                                className="max-sm:min-h-11"
                                onClick={() => {
                                    form.setData('min_select', preset.min);
                                    form.setData('max_select', preset.max);
                                    form.setData(
                                        'max_per_option',
                                        preset.perOption,
                                    );
                                }}
                            >
                                {preset.label}
                            </Button>
                        );
                    })}
                </div>

                <p className="rounded-lg border bg-muted/20 px-3 py-2 text-xs text-muted-foreground">
                    {describeSelectionRules(form.data)}
                </p>

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <Field data-invalid={!!form.errors.min_select}>
                        <FieldLabel htmlFor="group-min">
                            Pick at least
                        </FieldLabel>
                        <FieldDescription>
                            0 makes this group optional. 1 or more makes it
                            required.
                        </FieldDescription>
                        <Input
                            id="group-min"
                            type="number"
                            min={0}
                            className="tabular-nums"
                            value={form.data.min_select}
                            onChange={(event) =>
                                form.setData('min_select', event.target.value)
                            }
                            aria-invalid={!!form.errors.min_select}
                        />
                        <FieldError>{form.errors.min_select}</FieldError>
                    </Field>
                    <Field data-invalid={!!form.errors.max_select}>
                        <FieldLabel htmlFor="group-max">Pick up to</FieldLabel>
                        <FieldDescription>
                            Maximum total picks allowed. Leave blank for
                            unlimited.
                        </FieldDescription>
                        <Input
                            id="group-max"
                            type="number"
                            min={1}
                            placeholder="Unlimited"
                            className="tabular-nums"
                            value={form.data.max_select}
                            onChange={(event) =>
                                form.setData('max_select', event.target.value)
                            }
                            aria-invalid={!!form.errors.max_select}
                        />
                        <FieldError>{form.errors.max_select}</FieldError>
                    </Field>
                    <Field data-invalid={!!form.errors.max_per_option}>
                        <FieldLabel htmlFor="group-per">
                            Max repeats per choice
                        </FieldLabel>
                        <FieldDescription>
                            Keep at 1 for single tap, or higher to allow 2x or
                            3x of the same add-on.
                        </FieldDescription>
                        <Input
                            id="group-per"
                            type="number"
                            min={1}
                            className="tabular-nums"
                            value={form.data.max_per_option}
                            onChange={(event) =>
                                form.setData(
                                    'max_per_option',
                                    event.target.value,
                                )
                            }
                            aria-invalid={!!form.errors.max_per_option}
                        />
                        <FieldError>{form.errors.max_per_option}</FieldError>
                    </Field>
                    <Field data-invalid={!!form.errors.free_included_count}>
                        <FieldLabel htmlFor="group-free">
                            Included free
                        </FieldLabel>
                        <FieldDescription>
                            Number of paid choices waived before extra prices
                            apply.
                        </FieldDescription>
                        <Input
                            id="group-free"
                            type="number"
                            min={0}
                            className="tabular-nums"
                            value={form.data.free_included_count}
                            onChange={(event) =>
                                form.setData(
                                    'free_included_count',
                                    event.target.value,
                                )
                            }
                            aria-invalid={!!form.errors.free_included_count}
                        />
                        <FieldError>
                            {form.errors.free_included_count}
                        </FieldError>
                    </Field>
                </div>
            </FieldSet>

            <Separator />

            <FieldSet>
                <FieldLegend className="flex items-center gap-2">
                    <span>Choices</span>
                    <Badge
                        variant="outline"
                        className="font-normal tabular-nums"
                    >
                        {form.data.options.length}{' '}
                        {form.data.options.length === 1 ? 'choice' : 'choices'}
                    </Badge>
                </FieldLegend>
                <FieldDescription>
                    Add the options cashiers can tap. Leave extra price at 0.00
                    when included at no charge.
                </FieldDescription>

                <FieldError>{errors.options}</FieldError>

                <div className="flex flex-col gap-2.5">
                    {form.data.options.map((option, index) => {
                        const nameError = errors[`options.${index}.name`];
                        const priceError =
                            errors[`options.${index}.price_delta`];
                        const canRemove =
                            option.id === null && form.data.options.length > 1;

                        return (
                            <div
                                key={option.id ?? `new-${index}`}
                                className="flex flex-col gap-2.5 rounded-xl border bg-muted/15 p-3"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="text-xs font-medium text-muted-foreground tabular-nums">
                                        Choice {index + 1}
                                    </span>
                                    <div className="flex items-center gap-3">
                                        <label
                                            htmlFor={`option-active-${index}`}
                                            className="flex items-center gap-2 text-xs font-medium text-foreground"
                                        >
                                            <Switch
                                                id={`option-active-${index}`}
                                                checked={option.active}
                                                onCheckedChange={(checked) =>
                                                    patch(index, {
                                                        active: checked,
                                                    })
                                                }
                                            />
                                            <span>Active</span>
                                        </label>
                                        {canRemove && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                aria-label={`Remove choice ${option.name || index + 1}`}
                                                onClick={() =>
                                                    form.setData(
                                                        'options',
                                                        form.data.options.filter(
                                                            (_, at) =>
                                                                at !== index,
                                                        ),
                                                    )
                                                }
                                            >
                                                <Trash2 data-icon="inline-start" />
                                                Remove
                                            </Button>
                                        )}
                                    </div>
                                </div>

                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,1fr)_10rem]">
                                    <Field data-invalid={!!nameError}>
                                        <FieldLabel
                                            htmlFor={`option-name-${index}`}
                                        >
                                            Choice name
                                        </FieldLabel>
                                        <Input
                                            id={`option-name-${index}`}
                                            maxLength={100}
                                            placeholder="e.g. Oat milk, Medium rare"
                                            required
                                            value={option.name}
                                            onChange={(event) =>
                                                patch(index, {
                                                    name: event.target.value,
                                                })
                                            }
                                            aria-invalid={!!nameError}
                                        />
                                        <FieldError>{nameError}</FieldError>
                                    </Field>
                                    <Field data-invalid={!!priceError}>
                                        <FieldLabel
                                            htmlFor={`option-price-${index}`}
                                        >
                                            Extra price
                                        </FieldLabel>
                                        <MoneyInput
                                            id={`option-price-${index}`}
                                            currency={currency}
                                            value={option.priceDelta}
                                            invalid={!!priceError}
                                            onChange={(value) =>
                                                patch(index, {
                                                    priceDelta: value,
                                                })
                                            }
                                        />
                                        <FieldError>{priceError}</FieldError>
                                    </Field>
                                </div>
                            </div>
                        );
                    })}
                </div>

                <div className="flex justify-end">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="max-sm:min-h-11"
                        onClick={() =>
                            form.setData('options', [
                                ...form.data.options,
                                {
                                    id: null,
                                    name: '',
                                    priceDelta: '0.00',
                                    active: true,
                                    availability: 'all',
                                },
                            ])
                        }
                    >
                        <Plus data-icon="inline-start" />
                        Add choice
                    </Button>
                </div>
            </FieldSet>

            <Separator />

            <FieldSet>
                <FieldLegend>Linked menu items</FieldLegend>
                <Field>
                    <FieldLabel htmlFor="group-items">
                        Offered on these menu items
                    </FieldLabel>
                    <FieldDescription>
                        {items.length === 0
                            ? 'Add items on the Menu page first, then link this group to them.'
                            : 'Cashiers are prompted with this modifier group whenever any selected item is added to an order.'}
                    </FieldDescription>
                    <MultiCombobox
                        id="group-items"
                        name="item_ids"
                        items={items}
                        value={form.data.item_ids}
                        onValueChange={(value) =>
                            form.setData('item_ids', value)
                        }
                        placeholder="Search and select menu items"
                        emptyLabel="No matching items."
                        disabled={items.length === 0}
                    />
                </Field>
            </FieldSet>
        </FormOverlay>
    );
}
