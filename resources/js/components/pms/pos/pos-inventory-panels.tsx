import { router, useForm } from '@inertiajs/react';
import { ChevronDown, ChevronRight, Plus, Printer, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
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

const formatQty = (value: string) => Number(value).toFixed(2);

/**
 * Collapsible per-item recipe editor: shows only assigned ingredients with an "Add ingredient" selector, instead of an
 * N x M matrix.
 */
export function PosRecipeRow({
    variant,
    ingredients,
}: {
    variant: VariantRecipeRow;
    ingredients: IngredientRow[];
}) {
    const [open, setOpen] = useState(variant.recipe.length > 0);
    const [pickedId, setPickedId] = useState('');
    const initial = Object.fromEntries(
        variant.recipe.map((row) => [String(row.ingredientId), row.quantity]),
    );
    const form = useForm<{ quantities: Record<string, string> }>({
        quantities: initial,
    });

    const assignedIds = Object.keys(form.data.quantities);
    const ingredientById = new Map(
        ingredients.map((row) => [String(row.id), row]),
    );
    const availableOptions: EnumOption[] = ingredients
        .filter((row) => !assignedIds.includes(String(row.id)))
        .map((row) => ({
            value: String(row.id),
            label: `${row.name} (${row.baseUnit})`,
        }));

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
        <div className="rounded-lg border">
            <button
                type="button"
                aria-expanded={open}
                onClick={() => setOpen((value) => !value)}
                className="flex w-full items-center justify-between gap-2 px-3 py-2.5 text-left hover:bg-muted/40"
            >
                <span className="flex items-center gap-2 font-medium">
                    {open ? (
                        <ChevronDown className="size-4 text-muted-foreground" />
                    ) : (
                        <ChevronRight className="size-4 text-muted-foreground" />
                    )}
                    {variant.name}
                </span>
                <Badge variant="secondary" className="tabular-nums">
                    {assignedIds.length}{' '}
                    {assignedIds.length === 1 ? 'ingredient' : 'ingredients'}
                </Badge>
            </button>
            {open && (
                <form
                    className="flex flex-col gap-3 border-t p-3"
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
                        <p className="text-sm text-muted-foreground">
                            No ingredients assigned yet.
                        </p>
                    ) : (
                        <div className="flex flex-col gap-2">
                            {assignedIds.map((id) => {
                                const ing = ingredientById.get(id);

                                if (ing === undefined) {
                                    return null;
                                }

                                return (
                                    <div
                                        key={id}
                                        className="flex flex-wrap items-end gap-2"
                                    >
                                        <Field className="min-w-44 flex-1">
                                            <FieldLabel
                                                htmlFor={`r${variant.id}-${id}`}
                                            >
                                                {ing.name} ({ing.baseUnit})
                                            </FieldLabel>
                                            <Input
                                                id={`r${variant.id}-${id}`}
                                                inputMode="decimal"
                                                className="tabular-nums"
                                                placeholder={`Qty in ${ing.baseUnit}`}
                                                value={
                                                    form.data.quantities[id] ??
                                                    ''
                                                }
                                                onChange={(event) =>
                                                    form.setData('quantities', {
                                                        ...form.data.quantities,
                                                        [id]: event.target
                                                            .value,
                                                    })
                                                }
                                            />
                                        </Field>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            aria-label={`Remove ${ing.name}`}
                                            onClick={() => removeIngredient(id)}
                                        >
                                            <Trash2 />
                                        </Button>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                    <div className="flex flex-wrap items-end gap-2">
                        {availableOptions.length > 0 && (
                            <>
                                <div className="min-w-52 flex-1">
                                    <EnumSelect
                                        name={`recipe_add_${variant.id}`}
                                        options={availableOptions}
                                        value={pickedId}
                                        placeholder="Add ingredient"
                                        onValueChange={setPickedId}
                                    />
                                </div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={pickedId === ''}
                                    onClick={addIngredient}
                                >
                                    <Plus data-icon="inline-start" />
                                    Add
                                </Button>
                            </>
                        )}
                        <Button
                            type="submit"
                            size="sm"
                            className="ms-auto"
                            disabled={form.processing}
                            focusableWhenDisabled
                        >
                            {form.processing && (
                                <Spinner data-icon="inline-start" />
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

export function PosReceiveForm({
    outletId,
    ingredients,
    currencyCode,
}: {
    outletId: number;
    ingredients: EnumOption[];
    currencyCode: string;
}) {
    const form = useForm({ ingredient_id: '', quantity: '', unit_cost: '' });

    return (
        <form
            className="flex flex-col gap-3 rounded-lg border p-3"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(storePurchase.url({ outlet: outletId }), {
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                });
            }}
        >
            <h3 className="font-medium">Receive delivery</h3>
            <Field data-invalid={!!form.errors.ingredient_id}>
                <FieldLabel htmlFor="receive-ingredient">Ingredient</FieldLabel>
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
                    Quantity (purchase units)
                </FieldLabel>
                <Input
                    id="receive-qty"
                    inputMode="decimal"
                    className="tabular-nums"
                    value={form.data.quantity}
                    onChange={(event) =>
                        form.setData('quantity', event.target.value)
                    }
                />
                <FieldError>{form.errors.quantity}</FieldError>
            </Field>
            <Field data-invalid={!!form.errors.unit_cost}>
                <FieldLabel htmlFor="receive-cost">
                    Price per purchase unit ({currencyCode})
                </FieldLabel>
                <Input
                    id="receive-cost"
                    inputMode="decimal"
                    className="tabular-nums"
                    value={form.data.unit_cost}
                    onChange={(event) =>
                        form.setData('unit_cost', event.target.value)
                    }
                />
                <FieldError>{form.errors.unit_cost}</FieldError>
            </Field>
            <Button
                type="submit"
                variant="outline"
                disabled={form.processing}
                focusableWhenDisabled
            >
                {form.processing && <Spinner data-icon="inline-start" />}
                Receive
            </Button>
        </form>
    );
}

export function PosWasteForm({
    outletId,
    ingredients,
}: {
    outletId: number;
    ingredients: EnumOption[];
}) {
    const form = useForm({ ingredient_id: '', quantity: '', reason: '' });

    return (
        <form
            className="flex flex-col gap-3 rounded-lg border p-3"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(storeWaste.url({ outlet: outletId }), {
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                });
            }}
        >
            <h3 className="font-medium">Record waste</h3>
            <Field data-invalid={!!form.errors.ingredient_id}>
                <FieldLabel htmlFor="waste-ingredient">Ingredient</FieldLabel>
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
                    Quantity (base unit)
                </FieldLabel>
                <Input
                    id="waste-qty"
                    inputMode="decimal"
                    className="tabular-nums"
                    value={form.data.quantity}
                    onChange={(event) =>
                        form.setData('quantity', event.target.value)
                    }
                />
                <FieldError>{form.errors.quantity}</FieldError>
            </Field>
            <Field data-invalid={!!form.errors.reason}>
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
            <Button
                type="submit"
                variant="outline"
                disabled={form.processing}
                focusableWhenDisabled
            >
                {form.processing && <Spinner data-icon="inline-start" />}
                Record waste
            </Button>
        </form>
    );
}

export function PosTransferForm({
    fromOutletId,
    outlets,
    ingredients,
}: {
    fromOutletId: number;
    outlets: EnumOption[];
    ingredients: EnumOption[];
}) {
    const form = useForm({ ingredient_id: '', to_outlet_id: '', quantity: '' });

    return (
        <form
            className="flex flex-col gap-3 rounded-lg border p-3"
            onSubmit={(event) => {
                event.preventDefault();
                form.transform((data) => ({
                    ...data,
                    from_outlet_id: fromOutletId,
                }));
                form.post(storeTransfer.url(), {
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                });
            }}
        >
            <h3 className="font-medium">Transfer out</h3>
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
                <FieldLabel htmlFor="transfer-outlet">To outlet</FieldLabel>
                <EnumSelect
                    id="transfer-outlet"
                    name="to_outlet_id"
                    options={outlets.filter(
                        (row) => row.value !== String(fromOutletId),
                    )}
                    value={form.data.to_outlet_id}
                    placeholder="To outlet"
                    onValueChange={(value) =>
                        form.setData('to_outlet_id', value)
                    }
                />
                <FieldError>{form.errors.to_outlet_id}</FieldError>
            </Field>
            <Field data-invalid={!!form.errors.quantity}>
                <FieldLabel htmlFor="transfer-qty">
                    Quantity (base unit)
                </FieldLabel>
                <Input
                    id="transfer-qty"
                    inputMode="decimal"
                    className="tabular-nums"
                    value={form.data.quantity}
                    onChange={(event) =>
                        form.setData('quantity', event.target.value)
                    }
                />
                <FieldError>{form.errors.quantity}</FieldError>
            </Field>
            <Button
                type="submit"
                variant="outline"
                disabled={form.processing}
                focusableWhenDisabled
            >
                {form.processing && <Spinner data-icon="inline-start" />}
                Transfer
            </Button>
        </form>
    );
}

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
                className="flex items-center gap-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    start.post(storeStocktake.url({ outlet: outletId }), {
                        preserveScroll: true,
                    });
                }}
            >
                <Button
                    type="submit"
                    disabled={start.processing}
                    focusableWhenDisabled
                >
                    {start.processing && <Spinner data-icon="inline-start" />}
                    Start closing count
                </Button>
            </form>
        );
    }

    const counted = new Map(
        stocktake.lines.map((line) => [line.ingredientId, line]),
    );

    return (
        <div className="pos-print-sheet flex flex-col gap-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="font-medium">Closing count · {outletName}</h3>
                <div className="flex gap-2 print:hidden">
                    <Button variant="outline" onClick={() => window.print()}>
                        <Printer data-icon="inline-start" />
                        Print list
                    </Button>
                    <Button
                        disabled={stocktake.lines.length === 0}
                        focusableWhenDisabled
                        onClick={() =>
                            router.post(
                                closeStocktake.url({ stocktake: stocktake.id }),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        Close count
                    </Button>
                </div>
            </div>
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Ingredient</TableHead>
                        <TableHead className="text-end">On hand</TableHead>
                        <TableHead className="w-44">Counted</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {ingredients.map((row) => (
                        <CountRow
                            key={row.id}
                            stocktakeId={stocktake.id}
                            row={row}
                            counted={counted.get(row.id)?.counted ?? ''}
                        />
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}

function CountRow({
    stocktakeId,
    row,
    counted,
}: {
    stocktakeId: number;
    row: IngredientRow;
    counted: string;
}) {
    const form = useForm({ counted_quantity: counted });

    return (
        <TableRow>
            <TableCell>
                {row.name}{' '}
                <span className="text-muted-foreground">({row.baseUnit})</span>
            </TableCell>
            <TableCell className="text-end tabular-nums">
                {formatQty(row.onHand)}
            </TableCell>
            <TableCell>
                <form
                    className="flex gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(
                            countStocktake.url({
                                stocktake: stocktakeId,
                                ingredient: row.id,
                            }),
                            { preserveScroll: true },
                        );
                    }}
                >
                    <Input
                        aria-label={`Counted ${row.name}`}
                        inputMode="decimal"
                        className="tabular-nums"
                        value={form.data.counted_quantity}
                        onChange={(event) =>
                            form.setData('counted_quantity', event.target.value)
                        }
                    />
                    <Button
                        type="submit"
                        variant="outline"
                        size="sm"
                        className="print:hidden"
                        disabled={
                            form.processing ||
                            form.data.counted_quantity.trim() === ''
                        }
                        focusableWhenDisabled
                    >
                        Save
                    </Button>
                </form>
            </TableCell>
        </TableRow>
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
        purchase_to_base_ratio: row?.ratio ?? '1',
        low_stock_threshold: row?.threshold ?? '',
        active: row?.active ?? true,
    });

    return (
        <FormOverlay
            variant="sheet"
            open
            onOpenChange={(value) => !value && onClose()}
            title={row ? 'Edit ingredient' : 'Add ingredient'}
            errors={form.errors}
            processing={form.processing}
            submitLabel="Save ingredient"
            onSubmit={() => {
                const options = { preserveScroll: true, onSuccess: onClose };

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
            <Field data-invalid={!!form.errors.name}>
                <FieldLabel htmlFor="ing-name">Name</FieldLabel>
                <Input
                    id="ing-name"
                    maxLength={120}
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                    autoFocus
                />
                <FieldError>{form.errors.name}</FieldError>
            </Field>
            <Field data-invalid={!!form.errors.sku}>
                <FieldLabel htmlFor="ing-sku">SKU (optional)</FieldLabel>
                <Input
                    id="ing-sku"
                    maxLength={60}
                    value={form.data.sku}
                    onChange={(event) =>
                        form.setData('sku', event.target.value)
                    }
                />
                <FieldError>{form.errors.sku}</FieldError>
            </Field>
            <div className="grid grid-cols-2 gap-3">
                <Field data-invalid={!!form.errors.base_unit}>
                    <FieldLabel htmlFor="ing-base">Base unit</FieldLabel>
                    <Input
                        id="ing-base"
                        maxLength={20}
                        value={form.data.base_unit}
                        onChange={(event) =>
                            form.setData('base_unit', event.target.value)
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
                        value={form.data.purchase_unit}
                        onChange={(event) =>
                            form.setData('purchase_unit', event.target.value)
                        }
                    />
                    <FieldError>{form.errors.purchase_unit}</FieldError>
                </Field>
            </div>
            <div className="grid grid-cols-2 gap-3">
                <Field data-invalid={!!form.errors.purchase_to_base_ratio}>
                    <FieldLabel htmlFor="ing-ratio">
                        Base units per purchase unit
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
                <Field data-invalid={!!form.errors.low_stock_threshold}>
                    <FieldLabel htmlFor="ing-threshold">
                        Low stock at
                    </FieldLabel>
                    <Input
                        id="ing-threshold"
                        inputMode="decimal"
                        className="tabular-nums"
                        value={form.data.low_stock_threshold}
                        onChange={(event) =>
                            form.setData(
                                'low_stock_threshold',
                                event.target.value,
                            )
                        }
                    />
                    <FieldError>{form.errors.low_stock_threshold}</FieldError>
                </Field>
            </div>
            <Field orientation="horizontal">
                <Switch
                    id="ing-active"
                    checked={form.data.active}
                    onCheckedChange={(checked) =>
                        form.setData('active', checked)
                    }
                />
                <FieldLabel htmlFor="ing-active">Active</FieldLabel>
            </Field>
        </FormOverlay>
    );
}
