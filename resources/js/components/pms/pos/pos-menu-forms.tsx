import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { cn } from '@/lib/utils';
import {
    store as storeCategory,
    update as updateCategory,
} from '@/routes/settings/pos/categories';
import {
    store as storeItem,
    update as updateItem,
} from '@/routes/settings/pos/items';
import { update as updateOutletItem } from '@/routes/settings/pos/outlet-items';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { POS_CATEGORY_COLORS } from './pos-types';

export type MenuOutletItem = {
    outletId: number;
    availability: string;
    priceOverride: string | null;
    stockCountdown: number | null;
    dailyStockReset: number | null;
};

export type MenuVariant = {
    id: number | null;
    name: string;
    pricingType: string;
    price: string;
    cost: string | null;
    sku: string | null;
    barcode: string | null;
    active: boolean;
    outletItems?: MenuOutletItem[];
};

export type MenuItem = {
    id: number;
    name: string;
    transactionCodeId: number | null;
    mealPeriodId: number | null;
    internalName: string | null;
    description: string | null;
    priceIncludesTax: boolean;
    serviceChargeExempt: boolean;
    soldByWeight: boolean;
    weightUnit: string | null;
    weightUnitDivisor: string | null;
    sharedStockCount: number | null;
    dailySharedStockReset: number | null;
    sortOrder: number;
    active: boolean;
    variants: MenuVariant[];
};

export type MenuCategory = {
    id: number;
    name: string;
    transactionCodeId: number;
    internalName: string | null;
    colorToken: string;
    sortOrder: number;
    active: boolean;
    items: MenuItem[];
};

export function PosCategoryForm({
    row,
    codes,
    onClose,
}: {
    row: MenuCategory | null;
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
                <FieldError>{form.errors.name}</FieldError>
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
                <FieldError>{form.errors.transaction_code_id}</FieldError>
            </Field>
            <Field>
                <FieldLabel>Colour</FieldLabel>
                <div className="grid grid-cols-6 gap-2">
                    {POS_CATEGORY_COLORS.map((color) => {
                        const selected = form.data.color_token === color.value;

                        return (
                            <button
                                key={color.value}
                                type="button"
                                aria-label={color.label}
                                aria-pressed={selected}
                                title={color.label}
                                onClick={() =>
                                    form.setData('color_token', color.value)
                                }
                                className={cn(
                                    'flex h-9 items-center justify-center rounded-md border transition-all',
                                    selected
                                        ? 'ring-2 ring-ring ring-offset-2'
                                        : 'hover:border-foreground/40',
                                )}
                            >
                                <span
                                    className={cn(
                                        'size-5 rounded-full',
                                        color.swatchClass,
                                    )}
                                />
                            </button>
                        );
                    })}
                </div>
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

export function PosItemForm({
    row,
    categoryId,
    codes,
    mealPeriods,
    pricingTypes,
    onClose,
}: {
    row: MenuItem | null;
    categoryId: number;
    codes: EnumOption[];
    mealPeriods: EnumOption[];
    pricingTypes: EnumOption[];
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
        sold_by_weight: row?.soldByWeight ?? false,
        weight_unit: row?.weightUnit ?? 'g',
        weight_unit_divisor: row?.weightUnitDivisor ?? '100',
        shared_stock_count:
            row?.sharedStockCount !== null &&
            row?.sharedStockCount !== undefined
                ? String(row.sharedStockCount)
                : '',
        daily_shared_stock_reset:
            row?.dailySharedStockReset !== null &&
            row?.dailySharedStockReset !== undefined
                ? String(row.dailySharedStockReset)
                : '',
        sort_order: String(row?.sortOrder ?? 0),
        active: row?.active ?? true,
        variants: row?.variants ?? [
            {
                id: null,
                name: 'Regular',
                pricingType: 'fixed',
                price: '',
                cost: null,
                sku: null,
                barcode: null,
                active: true,
            },
        ],
    });

    function patchVariant(index: number, change: Partial<MenuVariant>) {
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
                    shared_stock_count:
                        data.shared_stock_count.trim() === ''
                            ? null
                            : Number(data.shared_stock_count),
                    daily_shared_stock_reset:
                        data.daily_shared_stock_reset.trim() === ''
                            ? null
                            : Number(data.daily_shared_stock_reset),
                    variants: data.variants.map((variant) => ({
                        id: variant.id,
                        name: variant.name,
                        pricing_type: variant.pricingType || 'fixed',
                        price: variant.price,
                        cost: variant.cost || null,
                        sku: variant.sku || null,
                        barcode: variant.barcode || null,
                        active: variant.active,
                    })),
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
                <FieldError>{form.errors.name}</FieldError>
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
            <Field data-invalid={!!form.errors.description}>
                <FieldLabel htmlFor="item-description">
                    Description (optional)
                </FieldLabel>
                <Textarea
                    id="item-description"
                    rows={2}
                    maxLength={500}
                    value={form.data.description}
                    onChange={(event) =>
                        form.setData('description', event.target.value)
                    }
                />
                <FieldError>{form.errors.description}</FieldError>
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
            <div className="grid grid-cols-2 gap-3">
                <Field>
                    <FieldLabel htmlFor="item-shared-stock">
                        Shared stock count
                    </FieldLabel>
                    <Input
                        id="item-shared-stock"
                        type="number"
                        min={0}
                        placeholder="Off"
                        value={form.data.shared_stock_count}
                        onChange={(event) =>
                            form.setData(
                                'shared_stock_count',
                                event.target.value,
                            )
                        }
                    />
                </Field>
                <Field>
                    <FieldLabel htmlFor="item-shared-reset">
                        Daily stock reset
                    </FieldLabel>
                    <Input
                        id="item-shared-reset"
                        type="number"
                        min={0}
                        placeholder="No auto reset"
                        value={form.data.daily_shared_stock_reset}
                        onChange={(event) =>
                            form.setData(
                                'daily_shared_stock_reset',
                                event.target.value,
                            )
                        }
                    />
                </Field>
            </div>
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
                    id="item-weight"
                    checked={form.data.sold_by_weight}
                    onCheckedChange={(checked) =>
                        form.setData('sold_by_weight', checked)
                    }
                />
                <FieldLabel htmlFor="item-weight">Sold by weight</FieldLabel>
            </Field>
            {form.data.sold_by_weight && (
                <div className="grid grid-cols-2 gap-3">
                    <Field data-invalid={!!form.errors.weight_unit}>
                        <FieldLabel htmlFor="item-weight-unit">Unit</FieldLabel>
                        <Input
                            id="item-weight-unit"
                            maxLength={20}
                            value={form.data.weight_unit}
                            onChange={(event) =>
                                form.setData('weight_unit', event.target.value)
                            }
                        />
                        <FieldError>{form.errors.weight_unit}</FieldError>
                    </Field>
                    <Field>
                        <FieldLabel htmlFor="item-weight-divisor">
                            Price is per
                        </FieldLabel>
                        <Input
                            id="item-weight-divisor"
                            inputMode="decimal"
                            className="tabular-nums"
                            value={form.data.weight_unit_divisor}
                            onChange={(event) =>
                                form.setData(
                                    'weight_unit_divisor',
                                    event.target.value,
                                )
                            }
                        />
                    </Field>
                </div>
            )}
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
                    <div
                        key={index}
                        className="flex flex-col gap-3 rounded-lg border p-3"
                    >
                        <div className="flex items-end gap-2">
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
                        <div className="grid grid-cols-2 gap-2">
                            <Field>
                                <FieldLabel htmlFor={`variant-pricing-${index}`}>
                                    Pricing
                                </FieldLabel>
                                <EnumSelect
                                    id={`variant-pricing-${index}`}
                                    name={`variants_${index}_pricing_type`}
                                    options={pricingTypes}
                                    value={variant.pricingType || 'fixed'}
                                    onValueChange={(value) =>
                                        patchVariant(index, {
                                            pricingType: value,
                                        })
                                    }
                                />
                            </Field>
                            <Field>
                                <FieldLabel htmlFor={`variant-cost-${index}`}>
                                    Unit cost
                                </FieldLabel>
                                <Input
                                    id={`variant-cost-${index}`}
                                    inputMode="decimal"
                                    className="tabular-nums"
                                    placeholder="Optional"
                                    value={variant.cost ?? ''}
                                    onChange={(event) =>
                                        patchVariant(index, {
                                            cost: event.target.value || null,
                                        })
                                    }
                                />
                            </Field>
                            <Field>
                                <FieldLabel htmlFor={`variant-sku-${index}`}>
                                    SKU
                                </FieldLabel>
                                <Input
                                    id={`variant-sku-${index}`}
                                    maxLength={60}
                                    placeholder="Optional"
                                    value={variant.sku ?? ''}
                                    onChange={(event) =>
                                        patchVariant(index, {
                                            sku: event.target.value || null,
                                        })
                                    }
                                />
                            </Field>
                            <Field>
                                <FieldLabel htmlFor={`variant-barcode-${index}`}>
                                    Barcode
                                </FieldLabel>
                                <Input
                                    id={`variant-barcode-${index}`}
                                    maxLength={60}
                                    placeholder="Optional"
                                    value={variant.barcode ?? ''}
                                    onChange={(event) =>
                                        patchVariant(index, {
                                            barcode: event.target.value || null,
                                        })
                                    }
                                />
                            </Field>
                        </div>
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
                                pricingType: 'fixed',
                                price: '',
                                cost: null,
                                sku: null,
                                barcode: null,
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

export function PosOutletOverrideSheet({
    item,
    outlets,
    availabilityOptions,
    onClose,
}: {
    item: MenuItem;
    outlets: EnumOption[];
    availabilityOptions: EnumOption[];
    onClose: () => void;
}) {
    const [outletId, setOutletId] = useState(outlets[0]?.value ?? '');
    const [variantId, setVariantId] = useState(
        String(item.variants[0]?.id ?? ''),
    );

    const variant = item.variants.find(
        (entry) => String(entry.id) === variantId,
    );
    const existing = variant?.outletItems?.find(
        (entry) => String(entry.outletId) === outletId,
    );

    return (
        <OutletOverrideForm
            key={`${outletId}-${variantId}`}
            item={item}
            outlets={outlets}
            outletId={outletId}
            variantId={variantId}
            onOutletChange={setOutletId}
            onVariantChange={setVariantId}
            availabilityOptions={availabilityOptions}
            existing={existing ?? null}
            onClose={onClose}
        />
    );
}

function OutletOverrideForm({
    item,
    outlets,
    outletId,
    variantId,
    onOutletChange,
    onVariantChange,
    availabilityOptions,
    existing,
    onClose,
}: {
    item: MenuItem;
    outlets: EnumOption[];
    outletId: string;
    variantId: string;
    onOutletChange: (value: string) => void;
    onVariantChange: (value: string) => void;
    availabilityOptions: EnumOption[];
    existing: MenuOutletItem | null;
    onClose: () => void;
}) {
    const form = useForm({
        availability: existing?.availability ?? 'available',
        price_override: existing?.priceOverride ?? '',
        stock_countdown:
            existing?.stockCountdown !== null &&
            existing?.stockCountdown !== undefined
                ? String(existing.stockCountdown)
                : '',
        daily_stock_reset:
            existing?.dailyStockReset !== null &&
            existing?.dailyStockReset !== undefined
                ? String(existing.dailyStockReset)
                : '',
    });

    const variantOptions = item.variants
        .filter((variant) => variant.id !== null)
        .map((variant) => ({
            value: String(variant.id),
            label: `${variant.name} (${variant.price})`,
        }));

    return (
        <FormOverlay
            variant="sheet"
            open
            onOpenChange={(value) => !value && onClose()}
            title={`Outlet overrides · ${item.name}`}
            description="Override availability, price, or stock countdown for a single outlet."
            errors={form.errors}
            processing={form.processing}
            submitLabel="Save override"
            onSubmit={() => {
                if (outletId === '' || variantId === '') {
                    return;
                }

                form.transform((data) => ({
                    availability: data.availability,
                    price_override:
                        data.price_override.trim() === ''
                            ? null
                            : data.price_override.trim(),
                    stock_countdown:
                        data.stock_countdown.trim() === ''
                            ? null
                            : Number(data.stock_countdown),
                    daily_stock_reset:
                        data.daily_stock_reset.trim() === ''
                            ? null
                            : Number(data.daily_stock_reset),
                }));
                form.put(
                    updateOutletItem.url({
                        outlet: Number(outletId),
                        variant: Number(variantId),
                    }),
                    { preserveScroll: true, onSuccess: onClose },
                );
            }}
        >
            <Field>
                <FieldLabel htmlFor="override-outlet">Outlet</FieldLabel>
                <EnumSelect
                    id="override-outlet"
                    name="outlet_id"
                    options={outlets}
                    value={outletId}
                    onValueChange={onOutletChange}
                />
            </Field>
            {variantOptions.length > 1 && (
                <Field>
                    <FieldLabel htmlFor="override-variant">Size</FieldLabel>
                    <EnumSelect
                        id="override-variant"
                        name="variant_id"
                        options={variantOptions}
                        value={variantId}
                        onValueChange={onVariantChange}
                    />
                </Field>
            )}
            <Field data-invalid={!!form.errors.availability}>
                <FieldLabel htmlFor="override-availability">
                    Availability
                </FieldLabel>
                <EnumSelect
                    id="override-availability"
                    name="availability"
                    options={availabilityOptions}
                    value={form.data.availability}
                    onValueChange={(value) =>
                        form.setData('availability', value)
                    }
                />
                <FieldError>{form.errors.availability}</FieldError>
            </Field>
            <Field data-invalid={!!form.errors.price_override}>
                <FieldLabel htmlFor="override-price">
                    Price override (blank uses default)
                </FieldLabel>
                <Input
                    id="override-price"
                    inputMode="decimal"
                    className="tabular-nums"
                    placeholder="Use default price"
                    value={form.data.price_override}
                    onChange={(event) =>
                        form.setData('price_override', event.target.value)
                    }
                />
                <FieldError>{form.errors.price_override}</FieldError>
            </Field>
            <div className="grid grid-cols-2 gap-3">
                <Field>
                    <FieldLabel htmlFor="override-stock">
                        Stock countdown
                    </FieldLabel>
                    <Input
                        id="override-stock"
                        type="number"
                        placeholder="Off"
                        value={form.data.stock_countdown}
                        onChange={(event) =>
                            form.setData('stock_countdown', event.target.value)
                        }
                    />
                </Field>
                <Field>
                    <FieldLabel htmlFor="override-reset">
                        Daily reset
                    </FieldLabel>
                    <Input
                        id="override-reset"
                        type="number"
                        min={0}
                        placeholder="No auto reset"
                        value={form.data.daily_stock_reset}
                        onChange={(event) =>
                            form.setData(
                                'daily_stock_reset',
                                event.target.value,
                            )
                        }
                    />
                </Field>
            </div>
        </FormOverlay>
    );
}
