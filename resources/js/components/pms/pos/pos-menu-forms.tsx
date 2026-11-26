import { useForm } from '@inertiajs/react';
import {
    AlertCircle,
    ChevronDown,
    Plus,
    SlidersHorizontal,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyInput } from '@/components/pms/money-input';
import { MoneyText } from '@/components/pms/money-text';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
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
import { Textarea } from '@/components/ui/textarea';
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
import { PosItemImageField } from './pos-item-image-field';
import { POS_CATEGORY_COLORS } from './pos-types';
import { unlessRefused } from './pos-unless-refused';

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
    imageUrl?: string | null;
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
            description="Group menu items together on the POS register and assign their default revenue account."
            errors={form.errors}
            processing={form.processing}
            submitLabel={row ? 'Save category' : 'Create category'}
            onSubmit={() => {
                const options = {
                    preserveScroll: true,
                    onSuccess: unlessRefused(onClose),
                };

                if (row) {
                    form.put(updateCategory.url({ category: row.id }), options);
                } else {
                    form.post(storeCategory.url(), options);
                }
            }}
        >
            <Field data-invalid={!!form.errors.name}>
                <FieldLabel htmlFor="cat-name">Category name</FieldLabel>
                <Input
                    id="cat-name"
                    maxLength={100}
                    placeholder="e.g. Mains, Drinks, Desserts"
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
            <Field data-invalid={!!form.errors.transaction_code_id}>
                <FieldLabel htmlFor="cat-code">Revenue account code</FieldLabel>
                <FieldDescription>
                    Default account used when items in this category post to
                    accounting or guest folios.
                </FieldDescription>
                {codes.length === 0 ? (
                    <Alert>
                        <AlertCircle aria-hidden />
                        <AlertDescription>
                            No charge transaction codes exist yet. Create a
                            charge code under Settings before adding categories.
                        </AlertDescription>
                    </Alert>
                ) : (
                    <EnumSelect
                        id="cat-code"
                        name="transaction_code_id"
                        options={codes}
                        value={form.data.transaction_code_id}
                        onValueChange={(value) =>
                            form.setData('transaction_code_id', value)
                        }
                        placeholder="Pick a revenue account"
                        aria-invalid={!!form.errors.transaction_code_id}
                    />
                )}
                <FieldError>{form.errors.transaction_code_id}</FieldError>
            </Field>
            <Field>
                <FieldLabel>Register colour</FieldLabel>
                <FieldDescription>
                    Accent dot shown next to this category on the POS register.
                </FieldDescription>
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
                                    'flex min-h-10 items-center justify-center rounded-md border transition-colors focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none motion-reduce:transition-none',
                                    selected
                                        ? 'ring-2 ring-ring ring-offset-2'
                                        : 'hover:border-foreground/40',
                                )}
                            >
                                <span
                                    aria-hidden
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
            <Field
                orientation="horizontal"
                className="items-start rounded-lg border bg-muted/20 p-3"
            >
                <Switch
                    id="cat-active"
                    checked={form.data.active}
                    onCheckedChange={(checked) =>
                        form.setData('active', checked)
                    }
                    className="mt-0.5"
                />
                <FieldContent>
                    <FieldLabel htmlFor="cat-active">
                        Show on POS register
                    </FieldLabel>
                    <FieldDescription>
                        Turn off to hide this category and its items from
                        cashiers without deleting them.
                    </FieldDescription>
                </FieldContent>
            </Field>
        </FormOverlay>
    );
}

function countAdvancedItemSettings(data: {
    internal_name: string;
    transaction_code_id: string;
    meal_period_id: string;
    price_includes_tax: boolean;
    service_charge_exempt: boolean;
    sold_by_weight: boolean;
    shared_stock_count: string;
    daily_shared_stock_reset: string;
}): number {
    let count = 0;

    if (data.internal_name.trim() !== '') {
        count += 1;
    }

    if (data.transaction_code_id !== '') {
        count += 1;
    }

    if (data.meal_period_id !== '') {
        count += 1;
    }

    if (data.price_includes_tax) {
        count += 1;
    }

    if (data.service_charge_exempt) {
        count += 1;
    }

    if (data.sold_by_weight) {
        count += 1;
    }

    if (
        data.shared_stock_count.trim() !== '' ||
        data.daily_shared_stock_reset.trim() !== ''
    ) {
        count += 1;
    }

    return count;
}

export function PosItemForm({
    row,
    currency,
    imageUrl = null,
    categoryId,
    categories,
    codes,
    mealPeriods,
    pricingTypes,
    onClose,
}: {
    row: MenuItem | null;
    currency: App.Data.Property.CurrencyData | null;
    /** The item's photo as the page has it now; `row` is a copy taken when the form opened. */
    imageUrl?: string | null;
    categoryId: number;
    categories?: EnumOption[];
    codes: EnumOption[];
    mealPeriods: EnumOption[];
    pricingTypes: EnumOption[];
    onClose: () => void;
}) {
    const form = useForm({
        image: null as File | null,
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

    const initialAdvancedCount = countAdvancedItemSettings(form.data);
    const [advancedOpen, setAdvancedOpen] = useState(initialAdvancedCount > 0);

    const hasAdvancedError = Boolean(
        form.errors.internal_name ||
        form.errors.transaction_code_id ||
        form.errors.meal_period_id ||
        form.errors.weight_unit ||
        form.errors.weight_unit_divisor ||
        form.errors.shared_stock_count ||
        form.errors.daily_shared_stock_reset,
    );
    const isAdvancedOpen = advancedOpen || hasAdvancedError;
    const advancedCount = countAdvancedItemSettings(form.data);

    function patchVariant(index: number, change: Partial<MenuVariant>) {
        form.setData(
            'variants',
            form.data.variants.map((variant, at) =>
                at === index ? { ...variant, ...change } : variant,
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
            title={row ? 'Edit menu item' : 'Add menu item'}
            description="Set the item name, sizes, and price shown on the POS register and guest QR menu."
            errors={form.errors}
            dirty={form.isDirty}
            discardTitle={
                row ? 'Discard changes to this item?' : 'Discard this new item?'
            }
            processing={form.processing}
            submitLabel={row ? 'Save item' : 'Create item'}
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
                const options = {
                    preserveScroll: true,
                    onSuccess: unlessRefused(onClose),
                };

                if (row) {
                    form.put(updateItem.url({ item: row.id }), options);
                } else {
                    form.post(storeItem.url(), options);
                }
            }}
        >
            <FieldSet>
                <FieldLegend>Item details</FieldLegend>
                <FieldDescription>
                    Basic details shown to cashiers and guests browsing the QR
                    menu.
                </FieldDescription>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field data-invalid={!!form.errors.name}>
                        <FieldLabel htmlFor="item-name">Item name</FieldLabel>
                        <Input
                            id="item-name"
                            maxLength={140}
                            placeholder="e.g. Nasi Lemak"
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
                    {categories && categories.length > 0 && (
                        <Field data-invalid={!!form.errors.pos_category_id}>
                            <FieldLabel htmlFor="item-category">
                                Category
                            </FieldLabel>
                            <EnumSelect
                                id="item-category"
                                name="pos_category_id"
                                options={categories}
                                value={form.data.pos_category_id}
                                onValueChange={(value) =>
                                    form.setData('pos_category_id', value)
                                }
                                aria-invalid={!!form.errors.pos_category_id}
                            />
                            <FieldError>
                                {form.errors.pos_category_id}
                            </FieldError>
                        </Field>
                    )}
                </div>

                <Field data-invalid={!!form.errors.description}>
                    <FieldLabel htmlFor="item-description">
                        Guest description (optional)
                    </FieldLabel>
                    <Textarea
                        id="item-description"
                        rows={2}
                        maxLength={500}
                        placeholder="Short description shown on the guest QR menu"
                        value={form.data.description}
                        onChange={(event) =>
                            form.setData('description', event.target.value)
                        }
                        aria-invalid={!!form.errors.description}
                    />
                    <FieldError>{form.errors.description}</FieldError>
                </Field>

                <PosItemImageField
                    itemId={row?.id ?? null}
                    imageUrl={imageUrl}
                    onPick={(file) => form.setData('image', file)}
                    formError={form.errors.image}
                />

                <Field
                    orientation="horizontal"
                    className="items-start rounded-lg border bg-muted/20 p-3"
                >
                    <Switch
                        id="item-active"
                        checked={form.data.active}
                        onCheckedChange={(checked) =>
                            form.setData('active', checked)
                        }
                        className="mt-0.5"
                    />
                    <FieldContent>
                        <FieldLabel htmlFor="item-active">
                            Active on register
                        </FieldLabel>
                        <FieldDescription>
                            Turn off to hide this item from cashiers and guest
                            QR menus without deleting past sales history.
                        </FieldDescription>
                    </FieldContent>
                </Field>
            </FieldSet>

            <Separator />

            <FieldSet>
                <FieldLegend className="flex items-center gap-2">
                    <span>Sizes and prices</span>
                    <Badge
                        variant="outline"
                        className="font-normal tabular-nums"
                    >
                        {form.data.variants.length}{' '}
                        {form.data.variants.length === 1 ? 'size' : 'sizes'}
                    </Badge>
                </FieldLegend>
                <FieldDescription>
                    Keep one Regular size for single-price items, or add sizes
                    such as Small, Large, Hot, or Iced.
                </FieldDescription>

                <FieldError>{errors.variants}</FieldError>

                <div className="flex flex-col gap-3">
                    {form.data.variants.map((variant, index) => {
                        const nameError = errors[`variants.${index}.name`];
                        const priceError = errors[`variants.${index}.price`];
                        const costError = errors[`variants.${index}.cost`];
                        const skuError = errors[`variants.${index}.sku`];
                        const barcodeError =
                            errors[`variants.${index}.barcode`];
                        const canRemove =
                            variant.id === null &&
                            form.data.variants.length > 1;

                        return (
                            <div
                                key={variant.id ?? `new-${index}`}
                                className="flex flex-col gap-3 rounded-xl border bg-muted/15 p-3.5"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2 border-b pb-2.5">
                                    <span className="text-xs font-medium text-muted-foreground tabular-nums">
                                        Size {index + 1}
                                        {variant.name.trim() !== ''
                                            ? ` · ${variant.name.trim()}`
                                            : ''}
                                    </span>
                                    <div className="flex items-center gap-3">
                                        <label
                                            htmlFor={`variant-active-${index}`}
                                            className="flex items-center gap-2 text-xs font-medium text-foreground"
                                        >
                                            <Switch
                                                id={`variant-active-${index}`}
                                                checked={variant.active}
                                                onCheckedChange={(checked) =>
                                                    patchVariant(index, {
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
                                                aria-label={`Remove size ${variant.name || index + 1}`}
                                                onClick={() =>
                                                    form.setData(
                                                        'variants',
                                                        form.data.variants.filter(
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

                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                    <Field data-invalid={!!nameError}>
                                        <FieldLabel
                                            htmlFor={`variant-name-${index}`}
                                        >
                                            Size name
                                        </FieldLabel>
                                        <Input
                                            id={`variant-name-${index}`}
                                            maxLength={80}
                                            placeholder="e.g. Regular"
                                            required
                                            value={variant.name}
                                            onChange={(event) =>
                                                patchVariant(index, {
                                                    name: event.target.value,
                                                })
                                            }
                                            aria-invalid={!!nameError}
                                        />
                                        <FieldError>{nameError}</FieldError>
                                    </Field>
                                    <Field>
                                        <FieldLabel
                                            htmlFor={`variant-pricing-${index}`}
                                        >
                                            Pricing mode
                                        </FieldLabel>
                                        <EnumSelect
                                            id={`variant-pricing-${index}`}
                                            name={`variants_${index}_pricing_type`}
                                            options={pricingTypes}
                                            value={
                                                variant.pricingType || 'fixed'
                                            }
                                            onValueChange={(value) =>
                                                patchVariant(index, {
                                                    pricingType: value,
                                                })
                                            }
                                        />
                                    </Field>
                                    <Field data-invalid={!!priceError}>
                                        <FieldLabel
                                            htmlFor={`variant-price-${index}`}
                                        >
                                            {variant.pricingType === 'variable'
                                                ? 'Default price'
                                                : 'Price'}
                                        </FieldLabel>
                                        <MoneyInput
                                            id={`variant-price-${index}`}
                                            currency={currency}
                                            value={variant.price}
                                            invalid={!!priceError}
                                            onChange={(value) =>
                                                patchVariant(index, {
                                                    price: value,
                                                })
                                            }
                                        />
                                        <FieldError>{priceError}</FieldError>
                                    </Field>
                                </div>

                                {variant.pricingType === 'variable' && (
                                    <p className="text-xs text-muted-foreground">
                                        Cashiers are prompted to enter the final
                                        price at the register. Enter 0.00 if
                                        there is no suggested starting price.
                                    </p>
                                )}

                                <div className="grid grid-cols-1 gap-3 border-t pt-3 sm:grid-cols-3">
                                    <Field data-invalid={!!costError}>
                                        <FieldLabel
                                            htmlFor={`variant-cost-${index}`}
                                        >
                                            Unit cost (optional)
                                        </FieldLabel>
                                        <MoneyInput
                                            id={`variant-cost-${index}`}
                                            currency={currency}
                                            placeholder="0.00"
                                            value={variant.cost ?? ''}
                                            invalid={!!costError}
                                            onChange={(value) =>
                                                patchVariant(index, {
                                                    cost: value || null,
                                                })
                                            }
                                        />
                                        <FieldError>{costError}</FieldError>
                                    </Field>
                                    <Field data-invalid={!!skuError}>
                                        <FieldLabel
                                            htmlFor={`variant-sku-${index}`}
                                        >
                                            SKU (optional)
                                        </FieldLabel>
                                        <Input
                                            id={`variant-sku-${index}`}
                                            maxLength={60}
                                            placeholder="Item code"
                                            autoCorrect="off"
                                            autoCapitalize="none"
                                            spellCheck={false}
                                            value={variant.sku ?? ''}
                                            onChange={(event) =>
                                                patchVariant(index, {
                                                    sku:
                                                        event.target.value ||
                                                        null,
                                                })
                                            }
                                            aria-invalid={!!skuError}
                                        />
                                        <FieldError>{skuError}</FieldError>
                                    </Field>
                                    <Field data-invalid={!!barcodeError}>
                                        <FieldLabel
                                            htmlFor={`variant-barcode-${index}`}
                                        >
                                            Barcode (optional)
                                        </FieldLabel>
                                        <Input
                                            id={`variant-barcode-${index}`}
                                            maxLength={60}
                                            placeholder="Scan or type"
                                            autoCorrect="off"
                                            autoCapitalize="none"
                                            spellCheck={false}
                                            value={variant.barcode ?? ''}
                                            onChange={(event) =>
                                                patchVariant(index, {
                                                    barcode:
                                                        event.target.value ||
                                                        null,
                                                })
                                            }
                                            aria-invalid={!!barcodeError}
                                        />
                                        <FieldError>{barcodeError}</FieldError>
                                    </Field>
                                </div>
                            </div>
                        );
                    })}
                </div>

                <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="text-xs text-muted-foreground">
                        Saved sizes stay in history; toggle Active off to stop
                        selling one.
                    </span>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="max-sm:min-h-11"
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
            </FieldSet>

            <Separator />

            <div>
                <Collapsible
                    open={isAdvancedOpen}
                    onOpenChange={setAdvancedOpen}
                >
                    <CollapsibleTrigger
                        render={
                            <Button
                                type="button"
                                variant="outline"
                                className="flex h-auto w-full items-center justify-between gap-3 px-3.5 py-3 text-left"
                            />
                        }
                    >
                        <span className="flex min-w-0 items-center gap-2.5">
                            <SlidersHorizontal
                                aria-hidden
                                className="size-4 shrink-0 text-muted-foreground"
                            />
                            <span className="flex min-w-0 flex-col gap-0.5">
                                <span className="text-sm font-medium text-foreground">
                                    Kitchen, tax, weight &amp; stock options
                                </span>
                                <span className="text-xs font-normal text-muted-foreground">
                                    Optional settings for KDS label, tax rules,
                                    weighed pricing, meal plans, and stock
                                    limits.
                                </span>
                            </span>
                        </span>
                        <span className="flex shrink-0 items-center gap-2">
                            {advancedCount > 0 && (
                                <Badge
                                    variant="secondary"
                                    className="tabular-nums"
                                >
                                    {advancedCount} set
                                </Badge>
                            )}
                            <ChevronDown
                                aria-hidden
                                className={cn(
                                    'size-4 text-muted-foreground transition-transform motion-reduce:transition-none',
                                    isAdvancedOpen && 'rotate-180',
                                )}
                            />
                        </span>
                    </CollapsibleTrigger>

                    <CollapsibleContent className="pt-4">
                        <div className="flex flex-col gap-5 rounded-xl border bg-muted/10 p-4">
                            <FieldSet>
                                <FieldLegend variant="label">
                                    Kitchen &amp; meal plan
                                </FieldLegend>
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <Field
                                        data-invalid={
                                            !!form.errors.internal_name
                                        }
                                    >
                                        <FieldLabel htmlFor="item-internal">
                                            Kitchen name (optional)
                                        </FieldLabel>
                                        <FieldDescription>
                                            Shorter name shown on kitchen
                                            tickets and KDS screens.
                                        </FieldDescription>
                                        <Input
                                            id="item-internal"
                                            maxLength={60}
                                            placeholder="Same as item name"
                                            value={form.data.internal_name}
                                            onChange={(event) =>
                                                form.setData(
                                                    'internal_name',
                                                    event.target.value,
                                                )
                                            }
                                            aria-invalid={
                                                !!form.errors.internal_name
                                            }
                                        />
                                        <FieldError>
                                            {form.errors.internal_name}
                                        </FieldError>
                                    </Field>
                                    <Field
                                        data-invalid={
                                            !!form.errors.meal_period_id
                                        }
                                    >
                                        <FieldLabel htmlFor="item-meal">
                                            Included meal period
                                        </FieldLabel>
                                        <FieldDescription>
                                            Lets hotel guests redeem this item
                                            against a room meal plan.
                                        </FieldDescription>
                                        <EnumSelect
                                            id="item-meal"
                                            name="meal_period_id"
                                            options={mealPeriods}
                                            allLabel="Not an included meal"
                                            value={form.data.meal_period_id}
                                            onValueChange={(value) =>
                                                form.setData(
                                                    'meal_period_id',
                                                    value,
                                                )
                                            }
                                            placeholder="Not an included meal"
                                        />
                                        <FieldError>
                                            {form.errors.meal_period_id}
                                        </FieldError>
                                    </Field>
                                </div>
                            </FieldSet>

                            <Separator />

                            <FieldSet>
                                <FieldLegend variant="label">
                                    Tax &amp; accounting
                                </FieldLegend>
                                <Field
                                    data-invalid={
                                        !!form.errors.transaction_code_id
                                    }
                                >
                                    <FieldLabel htmlFor="item-code">
                                        Account code override
                                    </FieldLabel>
                                    <FieldDescription>
                                        Leave blank to inherit the revenue
                                        account code from the item&apos;s
                                        category.
                                    </FieldDescription>
                                    <EnumSelect
                                        id="item-code"
                                        name="transaction_code_id"
                                        options={codes}
                                        allLabel="Use the category's account code"
                                        value={form.data.transaction_code_id}
                                        onValueChange={(value) =>
                                            form.setData(
                                                'transaction_code_id',
                                                value,
                                            )
                                        }
                                        placeholder="Use the category's account code"
                                    />
                                    <FieldError>
                                        {form.errors.transaction_code_id}
                                    </FieldError>
                                </Field>
                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <Field
                                        orientation="horizontal"
                                        className="items-start rounded-lg border bg-card p-3"
                                    >
                                        <Switch
                                            id="item-tax"
                                            checked={
                                                form.data.price_includes_tax
                                            }
                                            onCheckedChange={(checked) =>
                                                form.setData(
                                                    'price_includes_tax',
                                                    checked,
                                                )
                                            }
                                            className="mt-0.5"
                                        />
                                        <FieldContent>
                                            <FieldLabel htmlFor="item-tax">
                                                Prices include tax
                                            </FieldLabel>
                                            <FieldDescription>
                                                Tax is already built into the
                                                size prices above.
                                            </FieldDescription>
                                        </FieldContent>
                                    </Field>
                                    <Field
                                        orientation="horizontal"
                                        className="items-start rounded-lg border bg-card p-3"
                                    >
                                        <Switch
                                            id="item-sc"
                                            checked={
                                                form.data.service_charge_exempt
                                            }
                                            onCheckedChange={(checked) =>
                                                form.setData(
                                                    'service_charge_exempt',
                                                    checked,
                                                )
                                            }
                                            className="mt-0.5"
                                        />
                                        <FieldContent>
                                            <FieldLabel htmlFor="item-sc">
                                                Exempt from service charge
                                            </FieldLabel>
                                            <FieldDescription>
                                                Skip dining option service
                                                charge on this item.
                                            </FieldDescription>
                                        </FieldContent>
                                    </Field>
                                </div>
                            </FieldSet>

                            <Separator />

                            <FieldSet>
                                <FieldLegend variant="label">
                                    Weight &amp; shared stock
                                </FieldLegend>
                                <Field
                                    orientation="horizontal"
                                    className="items-start rounded-lg border bg-card p-3"
                                >
                                    <Switch
                                        id="item-weight"
                                        checked={form.data.sold_by_weight}
                                        onCheckedChange={(checked) =>
                                            form.setData(
                                                'sold_by_weight',
                                                checked,
                                            )
                                        }
                                        className="mt-0.5"
                                    />
                                    <FieldContent>
                                        <FieldLabel htmlFor="item-weight">
                                            Sold by weight
                                        </FieldLabel>
                                        <FieldDescription>
                                            Cashiers enter the measured weight
                                            at the register (for seafood,
                                            steaks, or deli items).
                                        </FieldDescription>
                                    </FieldContent>
                                </Field>

                                {form.data.sold_by_weight && (
                                    <div className="flex flex-col gap-3 rounded-lg border bg-card p-3">
                                        <div className="grid grid-cols-2 gap-3">
                                            <Field
                                                data-invalid={
                                                    !!form.errors.weight_unit
                                                }
                                            >
                                                <FieldLabel htmlFor="item-weight-unit">
                                                    Weight unit
                                                </FieldLabel>
                                                <Input
                                                    id="item-weight-unit"
                                                    maxLength={20}
                                                    placeholder="g or kg"
                                                    value={
                                                        form.data.weight_unit
                                                    }
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'weight_unit',
                                                            event.target.value,
                                                        )
                                                    }
                                                    aria-invalid={
                                                        !!form.errors
                                                            .weight_unit
                                                    }
                                                />
                                                <FieldError>
                                                    {form.errors.weight_unit}
                                                </FieldError>
                                            </Field>
                                            <Field
                                                data-invalid={
                                                    !!form.errors
                                                        .weight_unit_divisor
                                                }
                                            >
                                                <FieldLabel htmlFor="item-weight-divisor">
                                                    Price is per
                                                </FieldLabel>
                                                <Input
                                                    id="item-weight-divisor"
                                                    inputMode="decimal"
                                                    className="tabular-nums"
                                                    placeholder="100"
                                                    value={
                                                        form.data
                                                            .weight_unit_divisor
                                                    }
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'weight_unit_divisor',
                                                            event.target.value,
                                                        )
                                                    }
                                                    aria-invalid={
                                                        !!form.errors
                                                            .weight_unit_divisor
                                                    }
                                                />
                                                <FieldError>
                                                    {
                                                        form.errors
                                                            .weight_unit_divisor
                                                    }
                                                </FieldError>
                                            </Field>
                                        </div>
                                        <p className="text-xs text-muted-foreground tabular-nums">
                                            Price above is charged per{' '}
                                            <span className="font-medium text-foreground">
                                                {form.data
                                                    .weight_unit_divisor ||
                                                    '1'}{' '}
                                                {form.data.weight_unit ||
                                                    'unit'}
                                            </span>
                                            .
                                        </p>
                                    </div>
                                )}

                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <Field
                                        data-invalid={
                                            !!form.errors.shared_stock_count
                                        }
                                    >
                                        <FieldLabel htmlFor="item-shared-stock">
                                            Shared stock count
                                        </FieldLabel>
                                        <FieldDescription>
                                            Portions shared across all sizes.
                                            Leave blank for unlimited.
                                        </FieldDescription>
                                        <Input
                                            id="item-shared-stock"
                                            type="number"
                                            min={0}
                                            className="tabular-nums"
                                            placeholder="Unlimited"
                                            value={form.data.shared_stock_count}
                                            onChange={(event) =>
                                                form.setData(
                                                    'shared_stock_count',
                                                    event.target.value,
                                                )
                                            }
                                            aria-invalid={
                                                !!form.errors.shared_stock_count
                                            }
                                        />
                                        <FieldError>
                                            {form.errors.shared_stock_count}
                                        </FieldError>
                                    </Field>
                                    <Field
                                        data-invalid={
                                            !!form.errors
                                                .daily_shared_stock_reset
                                        }
                                    >
                                        <FieldLabel htmlFor="item-shared-reset">
                                            Daily stock reset
                                        </FieldLabel>
                                        <FieldDescription>
                                            Automatically restock to this number
                                            each new business day.
                                        </FieldDescription>
                                        <Input
                                            id="item-shared-reset"
                                            type="number"
                                            min={0}
                                            className="tabular-nums"
                                            placeholder="No auto reset"
                                            value={
                                                form.data
                                                    .daily_shared_stock_reset
                                            }
                                            onChange={(event) =>
                                                form.setData(
                                                    'daily_shared_stock_reset',
                                                    event.target.value,
                                                )
                                            }
                                            aria-invalid={
                                                !!form.errors
                                                    .daily_shared_stock_reset
                                            }
                                        />
                                        <FieldError>
                                            {
                                                form.errors
                                                    .daily_shared_stock_reset
                                            }
                                        </FieldError>
                                    </Field>
                                </div>
                            </FieldSet>
                        </div>
                    </CollapsibleContent>
                </Collapsible>
            </div>
        </FormOverlay>
    );
}

export function PosOutletOverrideSheet({
    item,
    currency,
    outlets,
    availabilityOptions,
    onClose,
}: {
    item: MenuItem;
    currency: App.Data.Property.CurrencyData | null;
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
            currency={currency}
            outlets={outlets}
            outletId={outletId}
            variantId={variantId}
            selectedVariant={variant ?? null}
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
    currency,
    outlets,
    outletId,
    variantId,
    selectedVariant,
    onOutletChange,
    onVariantChange,
    availabilityOptions,
    existing,
    onClose,
}: {
    item: MenuItem;
    currency: App.Data.Property.CurrencyData | null;
    outlets: EnumOption[];
    outletId: string;
    variantId: string;
    selectedVariant: MenuVariant | null;
    onOutletChange: (value: string) => void;
    onVariantChange: (value: string) => void;
    availabilityOptions: EnumOption[];
    existing: MenuOutletItem | null;
    onClose: () => void;
}) {
    const form = useForm({
        availability: existing?.availability ?? 'all',
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

    const outletNames = new Map(
        outlets.map((entry) => [entry.value, entry.label]),
    );
    const availabilityLabels = new Map(
        availabilityOptions.map((entry) => [entry.value, entry.label]),
    );

    const existingOverrides = item.variants.flatMap((variant) =>
        variant.id === null
            ? []
            : (variant.outletItems ?? []).map((entry) => ({
                  outletId: String(entry.outletId),
                  variantId: String(variant.id),
                  outletName:
                      outletNames.get(String(entry.outletId)) ??
                      `Outlet #${entry.outletId}`,
                  variantName: variant.name,
                  availabilityLabel:
                      availabilityLabels.get(entry.availability) ??
                      entry.availability,
                  priceOverride: entry.priceOverride,
                  stockCountdown: entry.stockCountdown,
              })),
    );

    const variantOptions = item.variants
        .filter((variant) => variant.id !== null)
        .map((variant) => ({
            value: String(variant.id),
            label: `${variant.name} (${currency ? `${currency.code} ` : ''}${variant.price})`,
        }));

    return (
        <FormOverlay
            variant="sheet"
            open
            onOpenChange={(value) => !value && onClose()}
            title={`Outlet rules · ${item.name}`}
            description="Customize availability, price, or stock countdown for a specific outlet without changing the main menu."
            errors={form.errors}
            dirty={form.isDirty}
            discardTitle="Discard outlet override changes?"
            processing={form.processing}
            submitLabel="Save outlet rule"
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
                    { preserveScroll: true, onSuccess: unlessRefused(onClose) },
                );
            }}
        >
            {existingOverrides.length > 0 && (
                <div className="flex flex-col gap-2 rounded-lg border bg-muted/20 p-3">
                    <span className="text-xs font-medium text-muted-foreground">
                        Configured overrides on this item (click to edit)
                    </span>
                    <div className="flex flex-wrap gap-1.5">
                        {existingOverrides.map((entry) => {
                            const active =
                                entry.outletId === outletId &&
                                entry.variantId === variantId;

                            return (
                                <Button
                                    key={`${entry.outletId}-${entry.variantId}`}
                                    type="button"
                                    size="sm"
                                    variant={active ? 'default' : 'outline'}
                                    aria-pressed={active}
                                    onClick={() => {
                                        onOutletChange(entry.outletId);
                                        onVariantChange(entry.variantId);
                                    }}
                                >
                                    <span>{entry.outletName}</span>
                                    {item.variants.length > 1 && (
                                        <span className="opacity-75">
                                            ({entry.variantName})
                                        </span>
                                    )}
                                    <span className="text-xs opacity-80">
                                        · {entry.availabilityLabel}
                                    </span>
                                </Button>
                            );
                        })}
                    </div>
                </div>
            )}

            <FieldSet>
                <FieldLegend variant="label">Outlet &amp; size</FieldLegend>
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
            </FieldSet>

            <Separator />

            <FieldSet>
                <FieldLegend variant="label">Override rules</FieldLegend>
                <Field data-invalid={!!form.errors.availability}>
                    <FieldLabel htmlFor="override-availability">
                        Availability at this outlet
                    </FieldLabel>
                    <FieldDescription>
                        Choose whether this size sells on both POS and guest QR
                        menus, POS only, or is temporarily unavailable.
                    </FieldDescription>
                    <EnumSelect
                        id="override-availability"
                        name="availability"
                        options={availabilityOptions}
                        value={form.data.availability}
                        onValueChange={(value) =>
                            form.setData('availability', value)
                        }
                        aria-invalid={!!form.errors.availability}
                    />
                    <FieldError>{form.errors.availability}</FieldError>
                </Field>

                <Field data-invalid={!!form.errors.price_override}>
                    <FieldLabel htmlFor="override-price">
                        Custom price for this outlet (optional)
                    </FieldLabel>
                    <FieldDescription className="tabular-nums">
                        {selectedVariant ? (
                            <>
                                Default menu price is{' '}
                                <MoneyText
                                    amount={selectedVariant.price}
                                    currency={currency}
                                />
                                . Leave blank to use the default price.
                            </>
                        ) : (
                            'Leave blank to use the default menu price.'
                        )}
                    </FieldDescription>
                    <MoneyInput
                        id="override-price"
                        currency={currency}
                        placeholder="Use default price"
                        value={form.data.price_override}
                        invalid={!!form.errors.price_override}
                        onChange={(value) =>
                            form.setData('price_override', value)
                        }
                    />
                    <FieldError>{form.errors.price_override}</FieldError>
                </Field>

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <Field data-invalid={!!form.errors.stock_countdown}>
                        <FieldLabel htmlFor="override-stock">
                            Stock countdown
                        </FieldLabel>
                        <FieldDescription>
                            Portions left at this outlet. Blank means unlimited.
                        </FieldDescription>
                        <Input
                            id="override-stock"
                            type="number"
                            className="tabular-nums"
                            placeholder="Unlimited"
                            value={form.data.stock_countdown}
                            onChange={(event) =>
                                form.setData(
                                    'stock_countdown',
                                    event.target.value,
                                )
                            }
                            aria-invalid={!!form.errors.stock_countdown}
                        />
                        <FieldError>{form.errors.stock_countdown}</FieldError>
                    </Field>
                    <Field data-invalid={!!form.errors.daily_stock_reset}>
                        <FieldLabel htmlFor="override-reset">
                            Daily reset
                        </FieldLabel>
                        <FieldDescription>
                            Reset this outlet&apos;s stock count every morning.
                        </FieldDescription>
                        <Input
                            id="override-reset"
                            type="number"
                            min={0}
                            className="tabular-nums"
                            placeholder="No auto reset"
                            value={form.data.daily_stock_reset}
                            onChange={(event) =>
                                form.setData(
                                    'daily_stock_reset',
                                    event.target.value,
                                )
                            }
                            aria-invalid={!!form.errors.daily_stock_reset}
                        />
                        <FieldError>{form.errors.daily_stock_reset}</FieldError>
                    </Field>
                </div>
            </FieldSet>
        </FormOverlay>
    );
}
