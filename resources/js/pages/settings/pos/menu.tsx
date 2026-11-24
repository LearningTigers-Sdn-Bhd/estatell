import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import {
    PosSettingsShell,
    posSettingsBreadcrumbs,
} from '@/components/pms/pos/pos-settings-tabs';
import { Badge } from '@/components/ui/badge';
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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    store as storeCategory,
    update as updateCategory,
} from '@/routes/settings/pos/categories';
import {
    store as storeItem,
    update as updateItem,
} from '@/routes/settings/pos/items';

type Variant = {
    id: number | null;
    name: string;
    price: string;
    sku: string | null;
    active: boolean;
};
type Item = {
    id: number;
    name: string;
    transactionCodeId: number | null;
    mealPeriodId: number | null;
    internalName: string | null;
    description: string | null;
    priceIncludesTax: boolean;
    serviceChargeExempt: boolean;
    sortOrder: number;
    active: boolean;
    variants: Variant[];
};
type Category = {
    id: number;
    name: string;
    transactionCodeId: number;
    internalName: string | null;
    colorToken: string;
    sortOrder: number;
    active: boolean;
    items: Item[];
};

type Props = {
    categories: Category[];
    transactionCodes: EnumOption[];
    mealPeriods: EnumOption[];
};

const COLORS = Array.from({ length: 12 }, (_, index) => ({
    value: `category-${index + 1}`,
    label: `Colour ${index + 1}`,
}));

export default function PosMenuSettings({
    categories,
    transactionCodes,
    mealPeriods,
}: Props) {
    const [category, setCategory] = useState<{ row: Category | null } | null>(
        null,
    );
    const [item, setItem] = useState<{
        row: Item | null;
        categoryId: number;
    } | null>(null);

    return (
        <PosSettingsShell activeTab="menu">
            <div className="flex flex-col gap-6">
                <div>
                    <Button onClick={() => setCategory({ row: null })}>
                        <Plus data-icon="inline-start" />
                        Add category
                    </Button>
                </div>
                {categories.map((row) => (
                    <Card key={row.id}>
                        <CardHeader className="flex flex-row items-start justify-between gap-4">
                            <div className="flex flex-col gap-1">
                                <CardTitle>{row.name}</CardTitle>
                                <CardDescription>
                                    {row.active
                                        ? 'Shown on the register.'
                                        : 'Hidden from the register.'}
                                </CardDescription>
                            </div>
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setCategory({ row })}
                                >
                                    Edit
                                </Button>
                                <Button
                                    size="sm"
                                    onClick={() =>
                                        setItem({
                                            row: null,
                                            categoryId: row.id,
                                        })
                                    }
                                >
                                    <Plus data-icon="inline-start" />
                                    Add item
                                </Button>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Item</TableHead>
                                        <TableHead>Prices</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="w-20">
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {row.items.map((entry) => (
                                        <TableRow key={entry.id}>
                                            <TableCell className="font-medium">
                                                {entry.name}
                                            </TableCell>
                                            <TableCell className="tabular-nums">
                                                {entry.variants
                                                    .map(
                                                        (variant) =>
                                                            `${variant.name} ${variant.price}`,
                                                    )
                                                    .join(' · ')}
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant="secondary">
                                                    {entry.active
                                                        ? 'Active'
                                                        : 'Inactive'}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        setItem({
                                                            row: entry,
                                                            categoryId: row.id,
                                                        })
                                                    }
                                                >
                                                    Edit
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                    {row.items.length === 0 && (
                                        <TableRow>
                                            <TableCell
                                                colSpan={4}
                                                className="text-muted-foreground"
                                            >
                                                No items yet.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                ))}
            </div>
            {category && (
                <CategoryForm
                    key={category.row?.id ?? 'new'}
                    row={category.row}
                    codes={transactionCodes}
                    onClose={() => setCategory(null)}
                />
            )}
            {item && (
                <ItemForm
                    key={item.row?.id ?? 'new'}
                    row={item.row}
                    categoryId={item.categoryId}
                    codes={transactionCodes}
                    mealPeriods={mealPeriods}
                    onClose={() => setItem(null)}
                />
            )}
        </PosSettingsShell>
    );
}

function CategoryForm({
    row,
    codes,
    onClose,
}: {
    row: Category | null;
    codes: EnumOption[];
    onClose: () => void;
}) {
    const form = useForm({
        name: row?.name ?? '',
        transaction_code_id: String(row?.transactionCodeId ?? ''),
        internal_name: row?.internalName ?? '',
        color_token: row?.colorToken ?? 'category-1',
        sort_order: String(row?.sortOrder ?? 0),
        active: row?.active ?? true,
    });

    return (
        <FormOverlay
            variant="dialog"
            open
            onOpenChange={(value) => !value && onClose()}
            title={row ? 'Edit category' : 'Add category'}
            description="The account code is the default revenue account of its items."
            errors={form.errors}
            processing={form.processing}
            submitLabel="Save category"
            onSubmit={() => {
                const options = { preserveScroll: true, onSuccess: onClose };

                if (row) {
                    form.put(updateCategory.url({ category: row.id }), options);
                } else {
                    form.post(storeCategory.url(), options);
                }
            }}
        >
            <Field data-invalid={!!form.errors.name}>
                <FieldLabel htmlFor="cat-name">Name</FieldLabel>
                <Input
                    id="cat-name"
                    maxLength={100}
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                    autoFocus
                />
            </Field>
            <Field data-invalid={!!form.errors.transaction_code_id}>
                <FieldLabel htmlFor="cat-code">Account code</FieldLabel>
                <EnumSelect
                    id="cat-code"
                    name="transaction_code_id"
                    options={codes}
                    value={form.data.transaction_code_id}
                    onValueChange={(value) =>
                        form.setData('transaction_code_id', value)
                    }
                    placeholder="Pick a code"
                />
            </Field>
            <Field>
                <FieldLabel htmlFor="cat-color">Colour</FieldLabel>
                <EnumSelect
                    id="cat-color"
                    name="color_token"
                    options={COLORS}
                    value={form.data.color_token}
                    onValueChange={(value) =>
                        form.setData('color_token', value)
                    }
                />
            </Field>
            <Field orientation="horizontal">
                <Switch
                    id="cat-active"
                    checked={form.data.active}
                    onCheckedChange={(checked) =>
                        form.setData('active', checked)
                    }
                />
                <FieldLabel htmlFor="cat-active">Active</FieldLabel>
            </Field>
        </FormOverlay>
    );
}

function ItemForm({
    row,
    categoryId,
    codes,
    mealPeriods,
    onClose,
}: {
    row: Item | null;
    categoryId: number;
    codes: EnumOption[];
    mealPeriods: EnumOption[];
    onClose: () => void;
}) {
    const form = useForm({
        name: row?.name ?? '',
        pos_category_id: String(categoryId),
        transaction_code_id: String(row?.transactionCodeId ?? ''),
        meal_period_id: String(row?.mealPeriodId ?? ''),
        internal_name: row?.internalName ?? '',
        description: row?.description ?? '',
        price_includes_tax: row?.priceIncludesTax ?? false,
        service_charge_exempt: row?.serviceChargeExempt ?? false,
        sort_order: String(row?.sortOrder ?? 0),
        active: row?.active ?? true,
        variants: row?.variants ?? [
            { id: null, name: 'Regular', price: '', sku: null, active: true },
        ],
    });

    function patchVariant(index: number, change: Partial<Variant>) {
        form.setData(
            'variants',
            form.data.variants.map((variant, at) =>
                at === index ? { ...variant, ...change } : variant,
            ),
        );
    }

    return (
        <FormOverlay
            variant="sheet"
            open
            onOpenChange={(value) => !value && onClose()}
            title={row ? 'Edit item' : 'Add item'}
            errors={form.errors}
            processing={form.processing}
            submitLabel="Save item"
            onSubmit={() => {
                form.transform((data) => ({
                    ...data,
                    transaction_code_id: data.transaction_code_id || null,
                    meal_period_id: data.meal_period_id || null,
                    internal_name: data.internal_name || null,
                    description: data.description || null,
                }));
                const options = { preserveScroll: true, onSuccess: onClose };

                if (row) {
                    form.put(updateItem.url({ item: row.id }), options);
                } else {
                    form.post(storeItem.url(), options);
                }
            }}
        >
            <Field data-invalid={!!form.errors.name}>
                <FieldLabel htmlFor="item-name">Name</FieldLabel>
                <Input
                    id="item-name"
                    maxLength={140}
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                    autoFocus
                />
            </Field>
            <Field>
                <FieldLabel htmlFor="item-internal">Kitchen name</FieldLabel>
                <Input
                    id="item-internal"
                    maxLength={60}
                    value={form.data.internal_name}
                    onChange={(event) =>
                        form.setData('internal_name', event.target.value)
                    }
                />
            </Field>
            <Field>
                <FieldLabel htmlFor="item-code">
                    Account code (overrides the category)
                </FieldLabel>
                <EnumSelect
                    id="item-code"
                    name="transaction_code_id"
                    options={codes}
                    value={form.data.transaction_code_id}
                    onValueChange={(value) =>
                        form.setData('transaction_code_id', value)
                    }
                    placeholder="Use the category's"
                />
            </Field>
            <Field>
                <FieldLabel htmlFor="item-meal">
                    Included meal period
                </FieldLabel>
                <EnumSelect
                    id="item-meal"
                    name="meal_period_id"
                    options={mealPeriods}
                    value={form.data.meal_period_id}
                    onValueChange={(value) =>
                        form.setData('meal_period_id', value)
                    }
                    placeholder="Not an included meal"
                />
            </Field>
            <Field orientation="horizontal">
                <Switch
                    id="item-tax"
                    checked={form.data.price_includes_tax}
                    onCheckedChange={(checked) =>
                        form.setData('price_includes_tax', checked)
                    }
                />
                <FieldLabel htmlFor="item-tax">Prices include tax</FieldLabel>
            </Field>
            <Field orientation="horizontal">
                <Switch
                    id="item-sc"
                    checked={form.data.service_charge_exempt}
                    onCheckedChange={(checked) =>
                        form.setData('service_charge_exempt', checked)
                    }
                />
                <FieldLabel htmlFor="item-sc">No service charge</FieldLabel>
            </Field>
            <Field orientation="horizontal">
                <Switch
                    id="item-active"
                    checked={form.data.active}
                    onCheckedChange={(checked) =>
                        form.setData('active', checked)
                    }
                />
                <FieldLabel htmlFor="item-active">Active</FieldLabel>
            </Field>
            <div className="flex flex-col gap-3">
                <p className="text-sm font-medium">Sizes and prices</p>
                {form.data.variants.map((variant, index) => (
                    <div key={index} className="flex items-end gap-2">
                        <Field className="flex-1">
                            <FieldLabel htmlFor={`variant-name-${index}`}>
                                Name
                            </FieldLabel>
                            <Input
                                id={`variant-name-${index}`}
                                value={variant.name}
                                onChange={(event) =>
                                    patchVariant(index, {
                                        name: event.target.value,
                                    })
                                }
                            />
                        </Field>
                        <Field
                            className="w-28"
                            data-invalid={
                                !!form.errors[`variants.${index}.price`]
                            }
                        >
                            <FieldLabel htmlFor={`variant-price-${index}`}>
                                Price
                            </FieldLabel>
                            <Input
                                id={`variant-price-${index}`}
                                inputMode="decimal"
                                className="tabular-nums"
                                value={variant.price}
                                onChange={(event) =>
                                    patchVariant(index, {
                                        price: event.target.value,
                                    })
                                }
                            />
                        </Field>
                        <Switch
                            aria-label={`${variant.name} active`}
                            checked={variant.active}
                            onCheckedChange={(checked) =>
                                patchVariant(index, { active: checked })
                            }
                        />
                        {variant.id === null &&
                            form.data.variants.length > 1 && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Remove size"
                                    onClick={() =>
                                        form.setData(
                                            'variants',
                                            form.data.variants.filter(
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
                        form.setData('variants', [
                            ...form.data.variants,
                            {
                                id: null,
                                name: '',
                                price: '',
                                sku: null,
                                active: true,
                            },
                        ])
                    }
                >
                    <Plus data-icon="inline-start" />
                    Add size
                </Button>
            </div>
        </FormOverlay>
    );
}

PosMenuSettings.layout = {
    breadcrumbs: posSettingsBreadcrumbs('menu'),
};
