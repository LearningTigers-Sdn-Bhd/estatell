import { router, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';

import { FormOverlay } from '@/components/pms/form-overlay';
import { MultiCombobox } from '@/components/pms/multi-combobox';
import type { MultiComboboxOption } from '@/components/pms/multi-combobox';
import {
    PosSettingsShell,
    posSettingsBreadcrumbs,
} from '@/components/pms/pos/pos-settings-tabs';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { availability } from '@/routes/settings/pos/modifier-options';
import {
    store as storeGroup,
    update as updateGroup,
} from '@/routes/settings/pos/modifier-groups';

type Option = {
    id: number | null;
    name: string;
    priceDelta: string;
    active: boolean;
    availability: string;
};
type Group = {
    id: number;
    name: string;
    minSelect: number;
    maxSelect: number | null;
    maxPerOption: number;
    freeIncludedCount: number;
    active: boolean;
    itemIds: number[];
    options: Option[];
};

const AVAILABILITY = [
    { value: 'all', label: 'On sale' },
    { value: 'pos_only', label: 'Register only' },
    { value: 'none', label: 'Sold out (86)' },
];

export default function PosModifiers({
    groups,
    items,
}: {
    groups: Group[];
    items: MultiComboboxOption[];
}) {
    const [editing, setEditing] = useState<{ row: Group | null } | null>(null);

    return (
        <PosSettingsShell activeTab="modifiers">
            <div className="flex flex-col gap-6">
                <div>
                    <Button onClick={() => setEditing({ row: null })}>
                        <Plus data-icon="inline-start" />
                        Add modifier group
                    </Button>
                </div>
                {groups.map((group) => (
                    <Card key={group.id}>
                        <CardHeader className="flex flex-row items-start justify-between gap-4">
                            <div className="flex flex-col gap-1">
                                <CardTitle>{group.name}</CardTitle>
                                <CardDescription>
                                    Pick {group.minSelect}
                                    {group.maxSelect !== null &&
                                        ` to ${group.maxSelect}`}{' '}
                                    · {group.freeIncludedCount} included free
                                </CardDescription>
                            </div>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setEditing({ row: group })}
                            >
                                Edit
                            </Button>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-2">
                            {group.options.map((option) => (
                                <div
                                    key={option.id}
                                    className="flex items-center justify-between gap-3"
                                >
                                    <span>
                                        {option.name}{' '}
                                        <span className="text-muted-foreground tabular-nums">
                                            {option.priceDelta}
                                        </span>
                                    </span>
                                    <EnumSelect
                                        name={`availability-${option.id}`}
                                        options={AVAILABILITY}
                                        value={option.availability}
                                        onValueChange={(value) =>
                                            router.post(
                                                availability.url({
                                                    option: option.id ?? 0,
                                                }),
                                                { availability: value },
                                                { preserveScroll: true },
                                            )
                                        }
                                        aria-label={`${option.name} availability`}
                                    />
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                ))}
            </div>
            {editing && (
                <GroupForm
                    key={editing.row?.id ?? 'new'}
                    row={editing.row}
                    items={items}
                    onClose={() => setEditing(null)}
                />
            )}
        </PosSettingsShell>
    );
}

function GroupForm({
    row,
    items,
    onClose,
}: {
    row: Group | null;
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

    function patch(index: number, change: Partial<Option>) {
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
                    autoFocus
                />
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
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="self-start"
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
        </FormOverlay>
    );
}

PosModifiers.layout = {
    breadcrumbs: posSettingsBreadcrumbs('modifiers'),
};
