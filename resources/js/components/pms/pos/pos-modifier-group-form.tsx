import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MultiCombobox } from '@/components/pms/multi-combobox';
import type { MultiComboboxOption } from '@/components/pms/multi-combobox';
import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import {
    store as storeGroup,
    update as updateGroup,
} from '@/routes/settings/pos/modifier-groups';

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

export function PosModifierGroupForm({
    row,
    items,
    onClose,
}: {
    row: ModifierGroupRow | null;
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

    return (
        <FormOverlay
            variant="sheet"
            open
            onOpenChange={(value) => !value && onClose()}
            title={row ? 'Edit modifier group' : 'Add modifier group'}
            errors={form.errors}
            dirty={form.isDirty}
            processing={form.processing}
            submitLabel="Save group"
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
                const options = { preserveScroll: true, onSuccess: onClose };

                if (row) {
                    form.put(updateGroup.url({ group: row.id }), options);
                } else {
                    form.post(storeGroup.url(), options);
                }
            }}
        >
            <Field data-invalid={!!form.errors.name}>
                <FieldLabel htmlFor="group-name">Name</FieldLabel>
                <Input
                    id="group-name"
                    maxLength={100}
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                    aria-invalid={!!form.errors.name}
                    autoFocus
                />
                <FieldError>{form.errors.name}</FieldError>
            </Field>
            <div className="grid grid-cols-2 gap-3">
                <Field>
                    <FieldLabel htmlFor="group-min">Pick at least</FieldLabel>
                    <Input
                        id="group-min"
                        type="number"
                        min={0}
                        className="tabular-nums"
                        value={form.data.min_select}
                        onChange={(event) =>
                            form.setData('min_select', event.target.value)
                        }
                    />
                </Field>
                <Field>
                    <FieldLabel htmlFor="group-max">Pick up to</FieldLabel>
                    <Input
                        id="group-max"
                        type="number"
                        min={1}
                        className="tabular-nums"
                        value={form.data.max_select}
                        onChange={(event) =>
                            form.setData('max_select', event.target.value)
                        }
                    />
                </Field>
                <Field>
                    <FieldLabel htmlFor="group-per">
                        Most of one choice
                    </FieldLabel>
                    <Input
                        id="group-per"
                        type="number"
                        min={1}
                        className="tabular-nums"
                        value={form.data.max_per_option}
                        onChange={(event) =>
                            form.setData('max_per_option', event.target.value)
                        }
                    />
                </Field>
                <Field>
                    <FieldLabel htmlFor="group-free">Included free</FieldLabel>
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
                    />
                </Field>
            </div>
            <Field>
                <FieldLabel>Offered on these items</FieldLabel>
                <MultiCombobox
                    id="group-items"
                    name="item_ids"
                    items={items}
                    value={form.data.item_ids}
                    onValueChange={(value) => form.setData('item_ids', value)}
                    placeholder="Pick items"
                    emptyLabel="No matching items."
                />
            </Field>
            <Field orientation="horizontal">
                <Switch
                    id="group-active"
                    checked={form.data.active}
                    onCheckedChange={(checked) =>
                        form.setData('active', checked)
                    }
                />
                <FieldLabel htmlFor="group-active">Active</FieldLabel>
            </Field>
            <div className="flex flex-col gap-3">
                <p className="text-sm font-medium">Choices</p>
                {form.data.options.map((option, index) => (
                    <div key={index} className="flex items-end gap-2">
                        <Field className="flex-1">
                            <FieldLabel htmlFor={`option-name-${index}`}>
                                Name
                            </FieldLabel>
                            <Input
                                id={`option-name-${index}`}
                                value={option.name}
                                onChange={(event) =>
                                    patch(index, { name: event.target.value })
                                }
                            />
                        </Field>
                        <Field className="w-28">
                            <FieldLabel htmlFor={`option-price-${index}`}>
                                Extra price
                            </FieldLabel>
                            <Input
                                id={`option-price-${index}`}
                                inputMode="decimal"
                                className="tabular-nums"
                                value={option.priceDelta}
                                onChange={(event) =>
                                    patch(index, {
                                        priceDelta: event.target.value,
                                    })
                                }
                            />
                        </Field>
                        {option.id === null && form.data.options.length > 1 && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                aria-label="Remove choice"
                                onClick={() =>
                                    form.setData(
                                        'options',
                                        form.data.options.filter(
                                            (_, at) => at !== index,
                                        ),
                                    )
                                }
                            >
                                <Trash2 />
                            </Button>
                        )}
                    </div>
                ))}
                <div className="flex justify-end">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
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
            </div>
        </FormOverlay>
    );
}
