import { router, useForm } from '@inertiajs/react';
import {
    ArrowLeftRight,
    ArrowRight,
    Check,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    ClipboardCheck,
    PackagePlus,
    Plus,
    Printer,
    Trash2,
    TriangleAlert,
    UtensilsCrossed,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Field,
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
import { Progress } from '@/components/ui/progress';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';
import {
    store as storeIngredient,
    update as updateIngredient,
} from '@/routes/settings/pos/ingredients';
import { store as storePurchase } from '@/routes/settings/pos/purchases';
import { update as updateRecipe } from '@/routes/settings/pos/recipes';
import {
    close as closeStocktake,
    count as countStocktake,
    store as storeStocktake,
} from '@/routes/settings/pos/stocktakes';
import { store as storeTransfer } from '@/routes/settings/pos/transfers';
import { store as storeWaste } from '@/routes/settings/pos/waste';
import { unlessRefused } from './pos-unless-refused';

export type IngredientRow = {
    id: number;
    name: string;
    sku: string | null;
    baseUnit: string;
    purchaseUnit: string | null;
    ratio: string;
    unitCost: string;
    threshold: string | null;
    active: boolean;
    onHand: string;
};

export type VariantRecipeRow = {
    id: number;
    name: string;
    recipe: { ingredientId: number; quantity: string }[];
};

export type StocktakeRow = {
    id: number;
    lines: { ingredientId: number; counted: string; countedAt: string }[];
};

export type MovementMode = 'receive' | 'waste' | 'transfer';

const BASE_UNIT_PRESETS = ['g', 'kg', 'ml', 'L', 'pcs'] as const;

export function formatQty(value: string | number): string {
    const parsed = Number(value);

    if (!Number.isFinite(parsed)) {
        return String(value);
    }

    return parsed.toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

export function formatRatio(value: string | number): string {
    const parsed = Number(value);

    return Number.isFinite(parsed) ? String(parsed) : String(value);
}

/**
 * Collapsible per-item recipe editor with live portion cost estimation and clean unit suffixes.
 */
export function PosRecipeRow({
    variant,
    ingredients,
    currencyCode = 'MYR',
}: {
    variant: VariantRecipeRow;
    ingredients: IngredientRow[];
    currencyCode?: string;
}) {
    const [open, setOpen] = useState(variant.recipe.length > 0);
    const [pickedId, setPickedId] = useState('');
    const initial = Object.fromEntries(
        variant.recipe.map((row) => [
            String(row.ingredientId),
            formatRatio(row.quantity),
        ]),
    );
    const form = useForm<{ quantities: Record<string, string> }>({
        quantities: initial,
    });

    const assignedIds = Object.keys(form.data.quantities);
    const ingredientById = useMemo(
        () => new Map(ingredients.map((row) => [String(row.id), row])),
        [ingredients],
    );
    const availableOptions: EnumOption[] = ingredients
        .filter((row) => !assignedIds.includes(String(row.id)))
        .map((row) => ({
            value: String(row.id),
            label: `${row.name} (${row.baseUnit})`,
        }));

    const estimatedCost = useMemo(() => {
        let total = 0;

        for (const id of assignedIds) {
            const ing = ingredientById.get(id);
            const qty = Number(form.data.quantities[id]);

            if (ing && Number.isFinite(qty) && qty > 0) {
                total += qty * Number(ing.unitCost);
            }
        }

        return total;
    }, [assignedIds, form.data.quantities, ingredientById]);

    const addIngredient = () => {
        if (pickedId === '') {
            return;
        }

        form.setData('quantities', {
            ...form.data.quantities,
            [pickedId]: '',
        });
        setPickedId('');
    };

    const removeIngredient = (id: string) => {
        const next = { ...form.data.quantities };
        delete next[id];
        form.setData('quantities', next);
    };

    return (
        <div className="overflow-hidden rounded-xl border bg-card">
            <button
                type="button"
                aria-expanded={open}
                onClick={() => setOpen((value) => !value)}
                className="flex w-full items-center justify-between gap-3 px-4 py-3.5 text-left transition-colors hover:bg-muted/35 focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none motion-reduce:transition-none"
            >
                <span className="flex min-w-0 items-center gap-3">
                    <span
                        className={cn(
                            'flex size-10 shrink-0 items-center justify-center rounded-xl border',
                            assignedIds.length > 0
                                ? 'border-success/30 bg-success/10 text-success'
                                : 'border-border bg-muted/60 text-muted-foreground',
                        )}
                    >
                        <UtensilsCrossed
                            aria-hidden="true"
                            className="size-4"
                        />
                    </span>
                    <span className="flex min-w-0 flex-col gap-0.5">
                        <span className="truncate font-medium text-foreground">
                            {variant.name}
                        </span>
                        <span className="text-xs text-muted-foreground tabular-nums">
                            {assignedIds.length === 0
                                ? 'No ingredients linked yet'
                                : `${assignedIds.length} ${assignedIds.length === 1 ? 'ingredient' : 'ingredients'} per portion`}
                        </span>
                    </span>
                </span>

                <span className="flex shrink-0 items-center gap-3">
                    {assignedIds.length > 0 && (
                        <span className="flex flex-col items-end text-right tabular-nums">
                            <span className="font-heading text-sm font-semibold text-foreground">
                                {currencyCode} {estimatedCost.toFixed(2)}
                            </span>
                            <span className="text-xs text-muted-foreground">
                                est. cost / portion
                            </span>
                        </span>
                    )}
                    {open ? (
                        <ChevronDown
                            aria-hidden="true"
                            className="size-4 text-muted-foreground"
                        />
                    ) : (
                        <ChevronRight
                            aria-hidden="true"
                            className="size-4 text-muted-foreground"
                        />
                    )}
                </span>
            </button>

            {open && (
                <form
                    className="flex flex-col gap-4 border-t bg-muted/15 p-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        const quantities = Object.fromEntries(
                            Object.entries(form.data.quantities).filter(
                                ([, value]) => value.trim() !== '',
                            ),
                        );
                        form.transform(() => ({ quantities }));
                        form.put(updateRecipe.url({ variant: variant.id }), {
                            preserveScroll: true,
                        });
                    }}
                >
                    {assignedIds.length === 0 ? (
                        <p className="rounded-lg border border-dashed bg-card/70 px-3.5 py-3 text-sm text-muted-foreground">
                            Pick an ingredient below to deduct stock
                            automatically whenever this dish fires to the
                            kitchen.
                        </p>
                    ) : (
                        <div className="flex flex-col gap-2">
                            {assignedIds.map((id) => {
                                const ing = ingredientById.get(id);

                                if (ing === undefined) {
                                    return null;
                                }

                                const qtyNum = Number(form.data.quantities[id]);
                                const lineCost =
                                    Number.isFinite(qtyNum) && qtyNum > 0
                                        ? qtyNum * Number(ing.unitCost)
                                        : 0;

                                return (
                                    <div
                                        key={id}
                                        className="flex flex-col gap-3 rounded-xl border bg-card p-3 sm:flex-row sm:items-center sm:justify-between"
                                    >
                                        <div className="flex min-w-0 flex-col gap-0.5">
                                            <FieldLabel
                                                htmlFor={`r${variant.id}-${id}`}
                                                className="text-sm font-medium text-foreground"
                                            >
                                                {ing.name}
                                            </FieldLabel>
                                            <span className="text-xs text-muted-foreground tabular-nums">
                                                {currencyCode}{' '}
                                                {Number(ing.unitCost).toFixed(
                                                    4,
                                                )}{' '}
                                                / {ing.baseUnit}
                                                {lineCost > 0
                                                    ? ` · Line cost: ${currencyCode} ${lineCost.toFixed(2)}`
                                                    : ''}
                                            </span>
                                        </div>

                                        <div className="flex items-center justify-end gap-2">
                                            <InputGroup className="w-36">
                                                <InputGroupInput
                                                    id={`r${variant.id}-${id}`}
                                                    inputMode="decimal"
                                                    className="text-end tabular-nums"
                                                    placeholder="0"
                                                    value={
                                                        form.data.quantities[
                                                            id
                                                        ] ?? ''
                                                    }
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'quantities',
                                                            {
                                                                ...form.data
                                                                    .quantities,
                                                                [id]: event
                                                                    .target
                                                                    .value,
                                                            },
                                                        )
                                                    }
                                                />
                                                <InputGroupAddon align="inline-end">
                                                    <InputGroupText>
                                                        {ing.baseUnit}
                                                    </InputGroupText>
                                                </InputGroupAddon>
                                            </InputGroup>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon-sm"
                                                className="max-sm:size-11"
                                                aria-label={`Remove ${ing.name} from ${variant.name}`}
                                                onClick={() =>
                                                    removeIngredient(id)
                                                }
                                            >
                                                <Trash2 />
                                            </Button>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}

                    <div className="flex flex-wrap items-center justify-between gap-2 border-t pt-3">
                        <div className="flex flex-1 flex-wrap items-center gap-2">
                            {availableOptions.length > 0 && (
                                <>
                                    <div className="min-w-48 flex-1 sm:max-w-xs">
                                        <EnumSelect
                                            name={`recipe_add_${variant.id}`}
                                            options={availableOptions}
                                            value={pickedId}
                                            placeholder="Add ingredient to recipe"
                                            onValueChange={setPickedId}
                                        />
                                    </div>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        className="max-sm:min-h-11"
                                        disabled={pickedId === ''}
                                        onClick={addIngredient}
                                    >
                                        <Plus data-icon="inline-start" />
                                        Add
                                    </Button>
                                </>
                            )}
                        </div>
                        <Button
                            type="submit"
                            size="sm"
                            className="ms-auto max-sm:min-h-11"
                            disabled={form.processing}
                            focusableWhenDisabled
                        >
                            {form.processing ? (
                                <Spinner data-icon="inline-start" />
                            ) : (
                                <Check data-icon="inline-start" />
                            )}
                            Save recipe
                        </Button>
                    </div>
                    <FieldError>{Object.values(form.errors)[0]}</FieldError>
                </form>
            )}
        </div>
    );
}

/**
 * Unified, guided Stock Movement workbench: pick Receive delivery, Record waste, or Transfer out
 * via visual mode cards, then complete one spacious form with a live stock-impact preview.
 */
export function PosStockMovementWorkbench({
    outletId,
    outletName,
    outlets,
    ingredients,
    ingredientList,
    currencyCode,
    mode,
    onModeChange,
}: {
    outletId: number;
    outletName: string;
    outlets: EnumOption[];
    ingredients: EnumOption[];
    ingredientList: IngredientRow[];
    currencyCode: string;
    mode: MovementMode;
    onModeChange: (mode: MovementMode) => void;
}) {
    const modes: {
        value: MovementMode;
        label: string;
        summary: string;
        icon: typeof PackagePlus;
        toneClass: string;
    }[] = [
        {
            value: 'receive',
            label: 'Receive delivery',
            summary: 'Add supplier stock and update average cost',
            icon: PackagePlus,
            toneClass: 'bg-success/10 text-success',
        },
        {
            value: 'waste',
            label: 'Record waste',
            summary: 'Deduct expired, spilled, or staff-meal stock',
            icon: TriangleAlert,
            toneClass: 'bg-warning/10 text-warning-text',
        },
        {
            value: 'transfer',
            label: 'Transfer out',
            summary: 'Move stock to another built-in POS outlet',
            icon: ArrowLeftRight,
            toneClass: 'bg-muted text-foreground',
        },
    ];

    return (
        <div className="flex flex-col gap-4">
            <div
                role="group"
                aria-label="Stock movement type"
                className="grid gap-3 sm:grid-cols-3"
            >
                {modes.map((item) => {
                    const Icon = item.icon;
                    const active = mode === item.value;

                    return (
                        <button
                            key={item.value}
                            type="button"
                            aria-pressed={active}
                            onClick={() => onModeChange(item.value)}
                            className={cn(
                                'flex items-start gap-3 rounded-xl border p-3.5 text-left transition-all focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none motion-reduce:transition-none',
                                active
                                    ? 'border-primary bg-card shadow-xs ring-1 ring-primary/30'
                                    : 'border-border bg-card/60 hover:bg-muted/35',
                            )}
                        >
                            <span
                                className={cn(
                                    'flex size-9 shrink-0 items-center justify-center rounded-lg',
                                    item.toneClass,
                                )}
                            >
                                <Icon aria-hidden="true" className="size-4" />
                            </span>
                            <span className="flex min-w-0 flex-col gap-0.5">
                                <span className="font-heading text-sm font-semibold text-foreground">
                                    {item.label}
                                </span>
                                <span className="text-xs leading-snug text-muted-foreground">
                                    {item.summary}
                                </span>
                            </span>
                        </button>
                    );
                })}
            </div>

            {mode === 'receive' && (
                <PosReceiveForm
                    outletId={outletId}
                    outletName={outletName}
                    ingredients={ingredients}
                    ingredientList={ingredientList}
                    currencyCode={currencyCode}
                />
            )}
            {mode === 'waste' && (
                <PosWasteForm
                    outletId={outletId}
                    outletName={outletName}
                    ingredients={ingredients}
                    ingredientList={ingredientList}
                />
            )}
            {mode === 'transfer' && (
                <PosTransferForm
                    fromOutletId={outletId}
                    outletName={outletName}
                    outlets={outlets}
                    ingredients={ingredients}
                    ingredientList={ingredientList}
                />
            )}
        </div>
    );
}

export function PosReceiveForm({
    outletId,
    outletName,
    ingredients,
    ingredientList = [],
    currencyCode,
}: {
    outletId: number;
    outletName?: string;
    ingredients: EnumOption[];
    ingredientList?: IngredientRow[];
    currencyCode: string;
}) {
    const form = useForm({ ingredient_id: '', quantity: '', unit_cost: '' });
    const selected = ingredientList.find(
        (row) => String(row.id) === form.data.ingredient_id,
    );
    const purchaseLabel = selected?.purchaseUnit || selected?.baseUnit || null;
    const qtyNum = Number(form.data.quantity);
    const addedBase =
        selected && Number.isFinite(qtyNum) && qtyNum > 0
            ? qtyNum * Number(selected.ratio || 1)
            : null;
    const projectedOnHand =
        selected && addedBase !== null
            ? Number(selected.onHand) + addedBase
            : null;

    return (
        <form
            className="flex flex-col gap-5 rounded-xl border bg-card p-5"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(storePurchase.url({ outlet: outletId }), {
                    preserveScroll: true,
                    onSuccess: unlessRefused(() => form.reset()),
                });
            }}
        >
            <div className="flex flex-col gap-0.5">
                <h3 className="font-heading text-base font-semibold text-foreground">
                    Receive supplier delivery
                    {outletName ? ` · ${outletName}` : ''}
                </h3>
                <p className="text-xs text-muted-foreground">
                    Enter the delivered purchase quantity and supplier price.
                    Stock is converted to the base unit automatically.
                </p>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    data-invalid={!!form.errors.ingredient_id}
                    className="sm:col-span-2"
                >
                    <FieldLabel htmlFor="receive-ingredient">
                        Ingredient
                    </FieldLabel>
                    <EnumSelect
                        id="receive-ingredient"
                        name="ingredient_id"
                        options={ingredients}
                        value={form.data.ingredient_id}
                        placeholder="Pick ingredient"
                        onValueChange={(value) =>
                            form.setData('ingredient_id', value)
                        }
                    />
                    <FieldError>{form.errors.ingredient_id}</FieldError>
                </Field>

                <Field data-invalid={!!form.errors.quantity}>
                    <FieldLabel htmlFor="receive-qty">
                        Quantity delivered ({purchaseLabel ?? 'purchase units'})
                    </FieldLabel>
                    <InputGroup>
                        <InputGroupInput
                            id="receive-qty"
                            inputMode="decimal"
                            className="tabular-nums"
                            placeholder="e.g. 5"
                            value={form.data.quantity}
                            onChange={(event) =>
                                form.setData('quantity', event.target.value)
                            }
                        />
                        {purchaseLabel && (
                            <InputGroupAddon align="inline-end">
                                <InputGroupText>{purchaseLabel}</InputGroupText>
                            </InputGroupAddon>
                        )}
                    </InputGroup>
                    <FieldError>{form.errors.quantity}</FieldError>
                </Field>

                <Field data-invalid={!!form.errors.unit_cost}>
                    <FieldLabel htmlFor="receive-cost">
                        Price per {purchaseLabel ?? 'purchase unit'} (
                        {currencyCode})
                    </FieldLabel>
                    <InputGroup>
                        <InputGroupAddon>
                            <InputGroupText>{currencyCode}</InputGroupText>
                        </InputGroupAddon>
                        <InputGroupInput
                            id="receive-cost"
                            inputMode="decimal"
                            className="tabular-nums"
                            placeholder="0.00"
                            value={form.data.unit_cost}
                            onChange={(event) =>
                                form.setData('unit_cost', event.target.value)
                            }
                        />
                    </InputGroup>
                    <FieldError>{form.errors.unit_cost}</FieldError>
                </Field>
            </div>

            {selected && (
                <div className="flex flex-wrap items-center justify-between gap-2 rounded-xl border bg-muted/30 px-4 py-3 text-xs tabular-nums">
                    <span className="text-muted-foreground">
                        Current on hand:{' '}
                        <strong className="font-medium text-foreground">
                            {formatQty(selected.onHand)} {selected.baseUnit}
                        </strong>
                        {selected.purchaseUnit
                            ? ` (1 ${selected.purchaseUnit} = ${formatRatio(selected.ratio)} ${selected.baseUnit})`
                            : ''}
                    </span>
                    {projectedOnHand !== null && addedBase !== null && (
                        <span className="inline-flex items-center gap-1.5 font-medium text-success">
                            <span>
                                +{formatQty(addedBase)} {selected.baseUnit}
                            </span>
                            <ArrowRight
                                aria-hidden="true"
                                className="size-3.5"
                            />
                            <span>
                                {formatQty(projectedOnHand)} {selected.baseUnit}
                            </span>
                        </span>
                    )}
                </div>
            )}

            <div className="flex justify-end border-t pt-4">
                <Button
                    type="submit"
                    className="max-sm:min-h-11"
                    disabled={form.processing || ingredients.length === 0}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    Receive delivery
                </Button>
            </div>
        </form>
    );
}

export function PosWasteForm({
    outletId,
    outletName,
    ingredients,
    ingredientList = [],
}: {
    outletId: number;
    outletName?: string;
    ingredients: EnumOption[];
    ingredientList?: IngredientRow[];
}) {
    const form = useForm({ ingredient_id: '', quantity: '', reason: '' });
    const selected = ingredientList.find(
        (row) => String(row.id) === form.data.ingredient_id,
    );
    const qtyNum = Number(form.data.quantity);
    const projectedOnHand =
        selected && Number.isFinite(qtyNum) && qtyNum > 0
            ? Number(selected.onHand) - qtyNum
            : null;

    return (
        <form
            className="flex flex-col gap-5 rounded-xl border bg-card p-5"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(storeWaste.url({ outlet: outletId }), {
                    preserveScroll: true,
                    onSuccess: unlessRefused(() => form.reset()),
                });
            }}
        >
            <div className="flex flex-col gap-0.5">
                <h3 className="font-heading text-base font-semibold text-foreground">
                    Record wastage{outletName ? ` · ${outletName}` : ''}
                </h3>
                <p className="text-xs text-muted-foreground">
                    Log spoiled, spilled, or staff-meal quantities in the
                    ingredient&apos;s base unit so stock stays accurate.
                </p>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <Field data-invalid={!!form.errors.ingredient_id}>
                    <FieldLabel htmlFor="waste-ingredient">
                        Ingredient
                    </FieldLabel>
                    <EnumSelect
                        id="waste-ingredient"
                        name="ingredient_id"
                        options={ingredients}
                        value={form.data.ingredient_id}
                        placeholder="Pick ingredient"
                        onValueChange={(value) =>
                            form.setData('ingredient_id', value)
                        }
                    />
                    <FieldError>{form.errors.ingredient_id}</FieldError>
                </Field>

                <Field data-invalid={!!form.errors.quantity}>
                    <FieldLabel htmlFor="waste-qty">
                        Quantity wasted ({selected?.baseUnit ?? 'base unit'})
                    </FieldLabel>
                    <InputGroup>
                        <InputGroupInput
                            id="waste-qty"
                            inputMode="decimal"
                            className="tabular-nums"
                            placeholder="e.g. 250"
                            value={form.data.quantity}
                            onChange={(event) =>
                                form.setData('quantity', event.target.value)
                            }
                        />
                        {selected && (
                            <InputGroupAddon align="inline-end">
                                <InputGroupText>
                                    {selected.baseUnit}
                                </InputGroupText>
                            </InputGroupAddon>
                        )}
                    </InputGroup>
                    <FieldError>{form.errors.quantity}</FieldError>
                </Field>

                <Field
                    data-invalid={!!form.errors.reason}
                    className="sm:col-span-2"
                >
                    <FieldLabel htmlFor="waste-reason">Reason</FieldLabel>
                    <Input
                        id="waste-reason"
                        placeholder="Expired, Spilled, Staff meal"
                        maxLength={200}
                        value={form.data.reason}
                        onChange={(event) =>
                            form.setData('reason', event.target.value)
                        }
                    />
                    <FieldError>{form.errors.reason}</FieldError>
                </Field>
            </div>

            {selected && (
                <div className="flex flex-wrap items-center justify-between gap-2 rounded-xl border bg-muted/30 px-4 py-3 text-xs tabular-nums">
                    <span className="text-muted-foreground">
                        Current on hand:{' '}
                        <strong className="font-medium text-foreground">
                            {formatQty(selected.onHand)} {selected.baseUnit}
                        </strong>
                    </span>
                    {projectedOnHand !== null && (
                        <span className="inline-flex items-center gap-1.5 font-medium text-warning-text">
                            <span>
                                -{formatQty(qtyNum)} {selected.baseUnit}
                            </span>
                            <ArrowRight
                                aria-hidden="true"
                                className="size-3.5"
                            />
                            <span>
                                {formatQty(projectedOnHand)} {selected.baseUnit}
                            </span>
                        </span>
                    )}
                </div>
            )}

            <div className="flex justify-end border-t pt-4">
                <Button
                    type="submit"
                    className="max-sm:min-h-11"
                    disabled={form.processing || ingredients.length === 0}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    Record waste
                </Button>
            </div>
        </form>
    );
}

export function PosTransferForm({
    fromOutletId,
    outletName,
    outlets,
    ingredients,
    ingredientList = [],
}: {
    fromOutletId: number;
    outletName?: string;
    outlets: EnumOption[];
    ingredients: EnumOption[];
    ingredientList?: IngredientRow[];
}) {
    const form = useForm({ ingredient_id: '', to_outlet_id: '', quantity: '' });
    const destinationOutlets = outlets.filter(
        (row) => row.value !== String(fromOutletId),
    );
    const selected = ingredientList.find(
        (row) => String(row.id) === form.data.ingredient_id,
    );

    return (
        <form
            className="flex flex-col gap-5 rounded-xl border bg-card p-5"
            onSubmit={(event) => {
                event.preventDefault();
                form.transform((data) => ({
                    ...data,
                    from_outlet_id: fromOutletId,
                }));
                form.post(storeTransfer.url(), {
                    preserveScroll: true,
                    onSuccess: unlessRefused(() => form.reset()),
                });
            }}
        >
            <div className="flex flex-col gap-0.5">
                <h3 className="font-heading text-base font-semibold text-foreground">
                    Transfer stock{outletName ? ` from ${outletName}` : ''}
                </h3>
                <p className="text-xs text-muted-foreground">
                    Deducts stock from this outlet and adds it to the receiving
                    outlet in a single movement.
                </p>
            </div>

            {destinationOutlets.length === 0 ? (
                <div className="rounded-xl border border-dashed bg-muted/20 p-5 text-sm text-muted-foreground">
                    This property currently has only one built-in POS outlet.
                    Enable built-in POS on a second outlet under Setup to
                    transfer ingredients between locations.
                </div>
            ) : (
                <>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field data-invalid={!!form.errors.ingredient_id}>
                            <FieldLabel htmlFor="transfer-ingredient">
                                Ingredient
                            </FieldLabel>
                            <EnumSelect
                                id="transfer-ingredient"
                                name="ingredient_id"
                                options={ingredients}
                                value={form.data.ingredient_id}
                                placeholder="Pick ingredient"
                                onValueChange={(value) =>
                                    form.setData('ingredient_id', value)
                                }
                            />
                            <FieldError>{form.errors.ingredient_id}</FieldError>
                        </Field>
                        <Field data-invalid={!!form.errors.to_outlet_id}>
                            <FieldLabel htmlFor="transfer-outlet">
                                Destination outlet
                            </FieldLabel>
                            <EnumSelect
                                id="transfer-outlet"
                                name="to_outlet_id"
                                options={destinationOutlets}
                                value={form.data.to_outlet_id}
                                placeholder="Pick outlet"
                                onValueChange={(value) =>
                                    form.setData('to_outlet_id', value)
                                }
                            />
                            <FieldError>{form.errors.to_outlet_id}</FieldError>
                        </Field>
                        <Field
                            data-invalid={!!form.errors.quantity}
                            className="sm:col-span-2"
                        >
                            <FieldLabel htmlFor="transfer-qty">
                                Quantity to transfer (
                                {selected?.baseUnit ?? 'base unit'})
                            </FieldLabel>
                            <InputGroup className="max-w-xs">
                                <InputGroupInput
                                    id="transfer-qty"
                                    inputMode="decimal"
                                    className="tabular-nums"
                                    placeholder="e.g. 500"
                                    value={form.data.quantity}
                                    onChange={(event) =>
                                        form.setData(
                                            'quantity',
                                            event.target.value,
                                        )
                                    }
                                />
                                {selected && (
                                    <InputGroupAddon align="inline-end">
                                        <InputGroupText>
                                            {selected.baseUnit}
                                        </InputGroupText>
                                    </InputGroupAddon>
                                )}
                            </InputGroup>
                            {selected && (
                                <FieldDescription className="tabular-nums">
                                    Available at {outletName ?? 'this outlet'}:{' '}
                                    {formatQty(selected.onHand)}{' '}
                                    {selected.baseUnit}
                                </FieldDescription>
                            )}
                            <FieldError>{form.errors.quantity}</FieldError>
                        </Field>
                    </div>
                    <div className="flex justify-end border-t pt-4">
                        <Button
                            type="submit"
                            className="max-sm:min-h-11"
                            disabled={
                                form.processing || ingredients.length === 0
                            }
                            focusableWhenDisabled
                        >
                            {form.processing && (
                                <Spinner data-icon="inline-start" />
                            )}
                            Transfer stock
                        </Button>
                    </div>
                </>
            )}
        </form>
    );
}

/**
 * Tactile Closing Count sheet modeled on the Meal check-in workspace rows:
 * unit badge tile, expected on-hand, live variance pill, one-tap Match button, and Save.
 */
export function PosStocktakePanel({
    outletId,
    outletName,
    ingredients,
    stocktake,
}: {
    outletId: number;
    outletName: string;
    ingredients: IngredientRow[];
    stocktake: StocktakeRow | null;
}) {
    const start = useForm({ note: '' });

    if (stocktake === null) {
        return (
            <form
                className="flex flex-col gap-5 rounded-xl border bg-card p-6 sm:flex-row sm:items-center sm:justify-between"
                onSubmit={(event) => {
                    event.preventDefault();
                    start.post(storeStocktake.url({ outlet: outletId }), {
                        preserveScroll: true,
                    });
                }}
            >
                <div className="flex items-start gap-3.5">
                    <span className="flex size-11 shrink-0 items-center justify-center rounded-xl border bg-muted/60 text-foreground">
                        <ClipboardCheck aria-hidden="true" className="size-5" />
                    </span>
                    <div className="flex flex-col gap-1">
                        <h3 className="font-heading text-base font-semibold text-foreground">
                            Closing count for {outletName}
                        </h3>
                        <p className="max-w-lg text-sm text-muted-foreground">
                            Open a physical stocktake sheet to verify actual
                            shelf counts against theoretical on-hand stock and
                            post adjustment variances.
                        </p>
                    </div>
                </div>
                <div className="flex shrink-0 justify-end">
                    <Button
                        type="submit"
                        className="max-sm:min-h-11"
                        disabled={start.processing || ingredients.length === 0}
                        focusableWhenDisabled
                    >
                        {start.processing && (
                            <Spinner data-icon="inline-start" />
                        )}
                        Start closing count
                    </Button>
                </div>
            </form>
        );
    }

    const counted = new Map(
        stocktake.lines.map((line) => [line.ingredientId, line]),
    );
    const countedCount = stocktake.lines.length;
    const totalCount = ingredients.length;
    const progressPct =
        totalCount > 0 ? Math.round((countedCount / totalCount) * 100) : 0;

    return (
        <section className="pos-print-sheet @container overflow-hidden rounded-xl border bg-card">
            <header className="flex flex-col gap-4 border-b bg-muted/40 p-4 sm:p-5">
                <div className="flex flex-col gap-3 @xl:flex-row @xl:items-start @xl:justify-between">
                    <div className="flex flex-col gap-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <h3 className="font-heading text-base font-semibold text-foreground">
                                Closing count · {outletName}
                            </h3>
                            <Badge
                                variant="secondary"
                                className={cn(
                                    'tabular-nums',
                                    countedCount === totalCount &&
                                        totalCount > 0
                                        ? 'bg-success/10 text-success'
                                        : 'bg-warning/10 text-warning-text',
                                )}
                            >
                                {countedCount} of {totalCount} counted (
                                {progressPct}%)
                            </Badge>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Enter each physical shelf count and save it, or tap
                            Match when actual stock equals expected. Close the
                            count once finished to post adjustments.
                        </p>
                    </div>

                    <div className="flex shrink-0 flex-wrap justify-end gap-2 print:hidden">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="max-sm:min-h-11"
                            onClick={() => window.print()}
                        >
                            <Printer data-icon="inline-start" />
                            Print sheet
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            className="max-sm:min-h-11"
                            disabled={stocktake.lines.length === 0}
                            focusableWhenDisabled
                            onClick={() =>
                                router.post(
                                    closeStocktake.url({
                                        stocktake: stocktake.id,
                                    }),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <CheckCircle2 data-icon="inline-start" />
                            Close count
                        </Button>
                    </div>
                </div>

                <Progress
                    value={progressPct}
                    aria-label="Closing count progress"
                    className="print:hidden"
                />
            </header>

            <div className="divide-y">
                {ingredients.map((row) => (
                    <CountRow
                        key={row.id}
                        stocktakeId={stocktake.id}
                        row={row}
                        savedCount={counted.get(row.id)?.counted ?? ''}
                    />
                ))}
            </div>
        </section>
    );
}

function CountRow({
    stocktakeId,
    row,
    savedCount,
}: {
    stocktakeId: number;
    row: IngredientRow;
    savedCount: string;
}) {
    const form = useForm({ counted_quantity: savedCount });
    const parsedCounted = Number(form.data.counted_quantity);
    const hasCount =
        form.data.counted_quantity.trim() !== '' &&
        Number.isFinite(parsedCounted);
    const expectedNum = Number(row.onHand);
    const variance = hasCount ? parsedCounted - expectedNum : null;
    const isSaved =
        savedCount !== '' &&
        form.data.counted_quantity.trim() === savedCount.trim();

    function submitCount(value: string) {
        form.transform(() => ({ counted_quantity: value }));
        form.put(
            countStocktake.url({
                stocktake: stocktakeId,
                ingredient: row.id,
            }),
            { preserveScroll: true },
        );
    }

    return (
        <div className="flex flex-col gap-3 px-4 py-3.5 transition-colors hover:bg-muted/25 motion-reduce:transition-none @xl:flex-row @xl:items-center @xl:justify-between">
            <div className="flex min-w-0 items-center gap-3.5">
                <div
                    className={cn(
                        'flex size-11 shrink-0 items-center justify-center rounded-xl border font-heading text-xs font-bold',
                        isSaved
                            ? 'border-success/30 bg-success/10 text-success'
                            : 'border-border bg-muted/60 text-foreground',
                    )}
                >
                    <span className="truncate px-1">{row.baseUnit}</span>
                </div>

                <div className="flex min-w-0 flex-col gap-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="truncate font-medium text-foreground">
                            {row.name}
                        </span>
                        {variance === null ? (
                            <Badge
                                variant="secondary"
                                className="bg-muted text-muted-foreground"
                            >
                                Pending count
                            </Badge>
                        ) : Math.abs(variance) < 0.005 ? (
                            <Badge
                                variant="secondary"
                                className="bg-success/10 text-success"
                            >
                                Exact match
                            </Badge>
                        ) : (
                            <Badge
                                variant="secondary"
                                className={cn(
                                    'tabular-nums',
                                    variance < 0
                                        ? 'bg-destructive/10 text-destructive'
                                        : 'bg-success/10 text-success',
                                )}
                            >
                                {variance > 0 ? '+' : ''}
                                {formatQty(variance)} {row.baseUnit}
                            </Badge>
                        )}
                    </div>
                    <span className="text-xs text-muted-foreground tabular-nums">
                        Expected on hand:{' '}
                        <strong className="font-medium text-foreground">
                            {formatQty(expectedNum)} {row.baseUnit}
                        </strong>
                    </span>
                </div>
            </div>

            <form
                className="flex flex-wrap items-center justify-end gap-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    submitCount(form.data.counted_quantity);
                }}
            >
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="max-sm:min-h-11 print:hidden"
                    aria-label={`Match expected quantity for ${row.name}`}
                    disabled={form.processing}
                    onClick={() => {
                        const expectedStr = String(expectedNum);
                        form.setData('counted_quantity', expectedStr);
                        submitCount(expectedStr);
                    }}
                >
                    Match
                </Button>

                <InputGroup className="w-36">
                    <InputGroupInput
                        aria-label={`Counted ${row.name}`}
                        inputMode="decimal"
                        placeholder="Actual"
                        className="text-end tabular-nums"
                        value={form.data.counted_quantity}
                        onChange={(event) =>
                            form.setData('counted_quantity', event.target.value)
                        }
                    />
                    <InputGroupAddon align="inline-end">
                        <InputGroupText>{row.baseUnit}</InputGroupText>
                    </InputGroupAddon>
                </InputGroup>

                <Button
                    type="submit"
                    variant={isSaved ? 'secondary' : 'outline'}
                    size="sm"
                    className="max-sm:min-h-11 print:hidden"
                    disabled={
                        form.processing ||
                        form.data.counted_quantity.trim() === ''
                    }
                    focusableWhenDisabled
                >
                    {form.processing ? (
                        <Spinner data-icon="inline-start" />
                    ) : isSaved ? (
                        <Check data-icon="inline-start" />
                    ) : null}
                    {isSaved ? 'Saved' : 'Save'}
                </Button>
            </form>
        </div>
    );
}

export function PosIngredientForm({
    row,
    onClose,
}: {
    row: IngredientRow | null;
    onClose: () => void;
}) {
    const form = useForm({
        name: row?.name ?? '',
        sku: row?.sku ?? '',
        base_unit: row?.baseUnit ?? 'g',
        purchase_unit: row?.purchaseUnit ?? '',
        purchase_to_base_ratio: row ? formatRatio(row.ratio) : '1',
        low_stock_threshold: row?.threshold ?? '',
        active: row?.active ?? true,
    });

    const baseUnitLabel = form.data.base_unit.trim() || 'unit';
    const purchaseUnitLabel = form.data.purchase_unit.trim();
    const ratioLabel = form.data.purchase_to_base_ratio.trim() || '1';

    return (
        <FormOverlay
            variant="sheet"
            size="lg"
            open
            onOpenChange={(value) => !value && onClose()}
            title={row ? `Edit ${row.name}` : 'Add ingredient'}
            description="Define how this ingredient is measured in recipes, bought from suppliers, and flagged when stock runs low."
            errors={form.errors}
            processing={form.processing}
            submitLabel={row ? 'Save ingredient' : 'Create ingredient'}
            onSubmit={() => {
                const options = {
                    preserveScroll: true,
                    onSuccess: unlessRefused(onClose),
                };

                if (row) {
                    form.put(
                        updateIngredient.url({ ingredient: row.id }),
                        options,
                    );
                } else {
                    form.post(storeIngredient.url(), options);
                }
            }}
        >
            <div className="flex flex-col gap-5">
                <div className="flex flex-col gap-4 rounded-xl border bg-card p-4">
                    <div className="flex flex-col gap-0.5">
                        <h3 className="text-sm font-semibold text-foreground">
                            Basics
                        </h3>
                        <p className="text-xs text-muted-foreground">
                            Name the raw ingredient as kitchen and purchasing
                            staff recognize it.
                        </p>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_10rem]">
                        <Field data-invalid={!!form.errors.name}>
                            <FieldLabel htmlFor="ing-name">Name</FieldLabel>
                            <Input
                                id="ing-name"
                                maxLength={120}
                                placeholder="e.g. Espresso Beans"
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                autoFocus
                            />
                            <FieldError>{form.errors.name}</FieldError>
                        </Field>
                        <Field data-invalid={!!form.errors.sku}>
                            <FieldLabel htmlFor="ing-sku">
                                SKU (optional)
                            </FieldLabel>
                            <Input
                                id="ing-sku"
                                maxLength={60}
                                placeholder="e.g. BEAN-01"
                                value={form.data.sku}
                                onChange={(event) =>
                                    form.setData('sku', event.target.value)
                                }
                            />
                            <FieldError>{form.errors.sku}</FieldError>
                        </Field>
                    </div>
                </div>

                <div className="flex flex-col gap-4 rounded-xl border bg-card p-4">
                    <div className="flex flex-col gap-0.5">
                        <h3 className="text-sm font-semibold text-foreground">
                            Units &amp; supplier conversion
                        </h3>
                        <p className="text-xs text-muted-foreground">
                            Recipes deduct in the base unit. Add a purchase unit
                            if suppliers deliver in cases, bags, or bottles.
                        </p>
                    </div>

                    <div className="flex flex-col gap-2">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <span className="text-xs font-medium text-muted-foreground">
                                Quick base units
                            </span>
                            <div className="flex flex-wrap gap-1.5">
                                {BASE_UNIT_PRESETS.map((unit) => (
                                    <Button
                                        key={unit}
                                        type="button"
                                        size="xs"
                                        variant={
                                            form.data.base_unit === unit
                                                ? 'secondary'
                                                : 'outline'
                                        }
                                        onClick={() =>
                                            form.setData('base_unit', unit)
                                        }
                                    >
                                        {unit}
                                    </Button>
                                ))}
                            </div>
                        </div>

                        <div className="grid gap-3 sm:grid-cols-3">
                            <Field data-invalid={!!form.errors.base_unit}>
                                <FieldLabel htmlFor="ing-base">
                                    Base unit
                                </FieldLabel>
                                <Input
                                    id="ing-base"
                                    maxLength={20}
                                    placeholder="g, ml, pcs"
                                    value={form.data.base_unit}
                                    onChange={(event) =>
                                        form.setData(
                                            'base_unit',
                                            event.target.value,
                                        )
                                    }
                                />
                                <FieldError>{form.errors.base_unit}</FieldError>
                            </Field>
                            <Field data-invalid={!!form.errors.purchase_unit}>
                                <FieldLabel htmlFor="ing-purchase">
                                    Purchase unit
                                </FieldLabel>
                                <Input
                                    id="ing-purchase"
                                    maxLength={20}
                                    placeholder="e.g. bag, case"
                                    value={form.data.purchase_unit}
                                    onChange={(event) =>
                                        form.setData(
                                            'purchase_unit',
                                            event.target.value,
                                        )
                                    }
                                />
                                <FieldError>
                                    {form.errors.purchase_unit}
                                </FieldError>
                            </Field>
                            <Field
                                data-invalid={
                                    !!form.errors.purchase_to_base_ratio
                                }
                            >
                                <FieldLabel htmlFor="ing-ratio">
                                    {baseUnitLabel} per purchase
                                </FieldLabel>
                                <Input
                                    id="ing-ratio"
                                    inputMode="decimal"
                                    className="tabular-nums"
                                    value={form.data.purchase_to_base_ratio}
                                    onChange={(event) =>
                                        form.setData(
                                            'purchase_to_base_ratio',
                                            event.target.value,
                                        )
                                    }
                                />
                                <FieldError>
                                    {form.errors.purchase_to_base_ratio}
                                </FieldError>
                            </Field>
                        </div>
                    </div>

                    <div className="rounded-lg bg-muted/40 px-3 py-2 text-xs text-muted-foreground tabular-nums">
                        {purchaseUnitLabel !== ''
                            ? `Conversion preview: 1 ${purchaseUnitLabel} delivered adds ${ratioLabel} ${baseUnitLabel} to stock.`
                            : `Deliveries and recipes will both be tracked directly in ${baseUnitLabel}.`}
                    </div>
                </div>

                <div className="flex flex-col gap-4 rounded-xl border bg-card p-4">
                    <Field data-invalid={!!form.errors.low_stock_threshold}>
                        <FieldLabel htmlFor="ing-threshold">
                            Low stock alert ({baseUnitLabel})
                        </FieldLabel>
                        <Input
                            id="ing-threshold"
                            inputMode="decimal"
                            className="tabular-nums"
                            placeholder="Leave blank to disable alerts"
                            value={form.data.low_stock_threshold}
                            onChange={(event) =>
                                form.setData(
                                    'low_stock_threshold',
                                    event.target.value,
                                )
                            }
                        />
                        <FieldDescription>
                            Flag this ingredient as Low stock when on-hand stock
                            drops to or below this level.
                        </FieldDescription>
                        <FieldError>
                            {form.errors.low_stock_threshold}
                        </FieldError>
                    </Field>
                </div>

                <div className="flex items-center justify-between gap-4 rounded-xl border bg-card p-4">
                    <div className="flex flex-col gap-0.5">
                        <FieldLabel
                            htmlFor="ing-active"
                            className="text-sm font-medium text-foreground"
                        >
                            Ingredient active
                        </FieldLabel>
                        <p className="text-xs text-muted-foreground">
                            Inactive ingredients stay in history but are hidden
                            from low-stock alerts.
                        </p>
                    </div>
                    <Switch
                        id="ing-active"
                        checked={form.data.active}
                        onCheckedChange={(checked) =>
                            form.setData('active', checked)
                        }
                    />
                </div>
            </div>
        </FormOverlay>
    );
}
