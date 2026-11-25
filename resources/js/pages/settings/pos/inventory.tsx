import { router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
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
import { index as inventoryIndex } from '@/routes/settings/pos/inventory';
import {
    store as storeIngredient,
    update as updateIngredient,
} from '@/routes/settings/pos/ingredients';
import { store as storePurchase } from '@/routes/settings/pos/purchases';
import { update as updateRecipe } from '@/routes/settings/pos/recipes';
import {
    store as storeStocktake,
    count as countStocktake,
    close as closeStocktake,
} from '@/routes/settings/pos/stocktakes';
import { store as storeTransfer } from '@/routes/settings/pos/transfers';
import { store as storeWaste } from '@/routes/settings/pos/waste';

type Ingredient = {
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

type Props = {
    outlets: EnumOption[];
    outlet: { id: number; name: string } | null;
    ingredients: Ingredient[];
    variants: {
        id: number;
        name: string;
        recipe: { ingredientId: number; quantity: string }[];
    }[];
    stocktake: {
        id: number;
        lines: { ingredientId: number; counted: string; countedAt: string }[];
    } | null;
    movementKinds: EnumOption[];
};

const money = (value: string) => Number(value).toFixed(2);

export default function PosInventory({
    outlets,
    outlet,
    ingredients,
    variants,
    stocktake,
}: Props) {
    const [editing, setEditing] = useState<Ingredient | 'new' | null>(null);

    const ingredientOptions: EnumOption[] = ingredients.map((row) => ({
        value: String(row.id),
        label: `${row.name} (${row.baseUnit})`,
    }));
    const outletOptions = outlets;

    return (
        <PosSettingsShell activeTab="inventory">
            <Card>
                <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-col gap-1">
                        <CardTitle>Ingredients</CardTitle>
                        <CardDescription>
                            Stock counted in its base unit. The cost is the
                            moving average of deliveries.
                        </CardDescription>
                    </div>
                    <Button onClick={() => setEditing('new')}>
                        <Plus data-icon="inline-start" />
                        Add ingredient
                    </Button>
                </CardHeader>
                <CardContent>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Base unit</TableHead>
                                <TableHead>Purchase unit</TableHead>
                                <TableHead className="text-end">
                                    Unit cost
                                </TableHead>
                                <TableHead className="text-end">
                                    On hand
                                    {outlet !== null ? ` · ${outlet.name}` : ''}
                                </TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {ingredients.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={6}
                                        className="text-muted-foreground"
                                    >
                                        No ingredients yet.
                                    </TableCell>
                                </TableRow>
                            )}
                            {ingredients.map((row) => (
                                <TableRow key={row.id}>
                                    <TableCell className="font-medium">
                                        {row.name}{' '}
                                        {!row.active && (
                                            <Badge variant="outline">Off</Badge>
                                        )}
                                    </TableCell>
                                    <TableCell>{row.baseUnit}</TableCell>
                                    <TableCell>
                                        {row.purchaseUnit ?? '—'}
                                        {row.purchaseUnit !== null && (
                                            <span className="text-muted-foreground">
                                                {' '}
                                                · {row.ratio} {row.baseUnit}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-end tabular-nums">
                                        RM {Number(row.unitCost).toFixed(4)}
                                    </TableCell>
                                    <TableCell className="text-end tabular-nums">
                                        {Number(row.onHand).toFixed(2)}
                                        {row.active &&
                                            row.threshold !== null &&
                                            Number(row.onHand) <=
                                                Number(row.threshold) && (
                                                <Badge
                                                    variant="outline"
                                                    className="ms-2 border-warning text-warning"
                                                >
                                                    Low stock
                                                </Badge>
                                            )}
                                    </TableCell>
                                    <TableCell className="text-end">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => setEditing(row)}
                                        >
                                            Edit
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Recipes</CardTitle>
                    <CardDescription>
                        What one portion of an item uses, in the base unit.
                        Firing the item takes this out of stock.
                    </CardDescription>
                </CardHeader>
                <CardContent className="flex flex-col gap-4">
                    {variants.map((variant) => (
                        <RecipeRow
                            key={variant.id}
                            variant={variant}
                            ingredients={ingredients}
                        />
                    ))}
                    {variants.length === 0 && (
                        <p className="text-muted-foreground">No items yet.</p>
                    )}
                </CardContent>
            </Card>

            {outlet !== null && (
                <Card>
                    <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3">
                        <div className="flex flex-col gap-1">
                            <CardTitle>Stock at {outlet.name}</CardTitle>
                            <CardDescription>
                                Deliveries, waste, transfers and the closing
                                count.
                            </CardDescription>
                        </div>
                        {outletOptions.length > 1 && (
                            <EnumSelect
                                options={outletOptions}
                                value={String(outlet.id)}
                                onValueChange={(value) =>
                                    router.get(
                                        inventoryIndex.url(undefined, {
                                            query: { outlet: value },
                                        }),
                                    )
                                }
                            />
                        )}
                    </CardHeader>
                    <CardContent className="flex flex-col gap-6">
                        <div className="grid gap-4 md:grid-cols-3">
                            <ReceiveForm
                                outletId={outlet.id}
                                ingredients={ingredientOptions}
                            />
                            <WasteForm
                                outletId={outlet.id}
                                ingredients={ingredientOptions}
                            />
                            <TransferForm
                                fromOutletId={outlet.id}
                                outlets={outletOptions}
                                ingredients={ingredientOptions}
                            />
                        </div>
                        <StocktakePanel
                            outletId={outlet.id}
                            ingredients={ingredients}
                            stocktake={stocktake}
                        />
                    </CardContent>
                </Card>
            )}

            {editing !== null && (
                <IngredientForm
                    row={editing === 'new' ? null : editing}
                    onClose={() => setEditing(null)}
                />
            )}
        </PosSettingsShell>
    );
}

function RecipeRow({
    variant,
    ingredients,
}: {
    variant: Props['variants'][number];
    ingredients: Ingredient[];
}) {
    const initial = Object.fromEntries(
        variant.recipe.map((row) => [String(row.ingredientId), row.quantity]),
    );
    const form = useForm<{ quantities: Record<string, string> }>({
        quantities: initial,
    });

    return (
        <form
            className="flex flex-col gap-2 rounded-lg border p-3"
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
            <div className="flex items-center justify-between gap-2">
                <span className="font-medium">{variant.name}</span>
                <Button type="submit" size="sm" disabled={form.processing}>
                    Save recipe
                </Button>
            </div>
            <div className="grid grid-cols-2 gap-2 md:grid-cols-4">
                {ingredients.map((row) => (
                    <Field key={row.id}>
                        <FieldLabel htmlFor={`r${variant.id}-${row.id}`}>
                            {row.name} ({row.baseUnit})
                        </FieldLabel>
                        <Input
                            id={`r${variant.id}-${row.id}`}
                            inputMode="decimal"
                            value={form.data.quantities[String(row.id)] ?? ''}
                            onChange={(event) =>
                                form.setData('quantities', {
                                    ...form.data.quantities,
                                    [String(row.id)]: event.target.value,
                                })
                            }
                        />
                    </Field>
                ))}
            </div>
        </form>
    );
}

function ReceiveForm({
    outletId,
    ingredients,
}: {
    outletId: number;
    ingredients: EnumOption[];
}) {
    const form = useForm({ ingredient_id: '', quantity: '', unit_cost: '' });

    return (
        <form
            className="flex flex-col gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(storePurchase.url({ outlet: outletId }), {
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                });
            }}
        >
            <h3 className="font-medium">Receive delivery</h3>
            <EnumSelect
                options={ingredients}
                value={form.data.ingredient_id}
                placeholder="Pick ingredient"
                onValueChange={(value) => form.setData('ingredient_id', value)}
            />
            <Input
                placeholder="Quantity in purchase units"
                inputMode="decimal"
                value={form.data.quantity}
                onChange={(event) =>
                    form.setData('quantity', event.target.value)
                }
            />
            <Input
                placeholder="Price per purchase unit (RM)"
                inputMode="decimal"
                value={form.data.unit_cost}
                onChange={(event) =>
                    form.setData('unit_cost', event.target.value)
                }
            />
            <Button type="submit" variant="outline" disabled={form.processing}>
                Receive
            </Button>
        </form>
    );
}

function WasteForm({
    outletId,
    ingredients,
}: {
    outletId: number;
    ingredients: EnumOption[];
}) {
    const form = useForm({ ingredient_id: '', quantity: '', reason: '' });

    return (
        <form
            className="flex flex-col gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(storeWaste.url({ outlet: outletId }), {
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                });
            }}
        >
            <h3 className="font-medium">Record waste</h3>
            <EnumSelect
                options={ingredients}
                value={form.data.ingredient_id}
                placeholder="Pick ingredient"
                onValueChange={(value) => form.setData('ingredient_id', value)}
            />
            <Input
                placeholder="Quantity in base unit"
                inputMode="decimal"
                value={form.data.quantity}
                onChange={(event) =>
                    form.setData('quantity', event.target.value)
                }
            />
            <Input
                placeholder="Reason (Expired, Spilled, Staff meal)"
                maxLength={200}
                value={form.data.reason}
                onChange={(event) => form.setData('reason', event.target.value)}
            />
            <Button type="submit" variant="outline" disabled={form.processing}>
                Record waste
            </Button>
        </form>
    );
}

function TransferForm({
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
            className="flex flex-col gap-2"
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
            <EnumSelect
                options={ingredients}
                value={form.data.ingredient_id}
                placeholder="Pick ingredient"
                onValueChange={(value) => form.setData('ingredient_id', value)}
            />
            <EnumSelect
                options={outlets.filter(
                    (row) => row.value !== String(fromOutletId),
                )}
                value={form.data.to_outlet_id}
                placeholder="To outlet"
                onValueChange={(value) => form.setData('to_outlet_id', value)}
            />
            <Input
                placeholder="Quantity in base unit"
                inputMode="decimal"
                value={form.data.quantity}
                onChange={(event) =>
                    form.setData('quantity', event.target.value)
                }
            />
            <Button type="submit" variant="outline" disabled={form.processing}>
                Transfer
            </Button>
        </form>
    );
}

function StocktakePanel({
    outletId,
    ingredients,
    stocktake,
}: {
    outletId: number;
    ingredients: Ingredient[];
    stocktake: Props['stocktake'];
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
                <Button type="submit" disabled={start.processing}>
                    Start closing count
                </Button>
            </form>
        );
    }

    const counted = new Map(
        stocktake.lines.map((line) => [line.ingredientId, line]),
    );

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="font-medium">Closing count</h3>
                <div className="flex gap-2">
                    <Button variant="outline" onClick={() => window.print()}>
                        Print list
                    </Button>
                    <Button
                        disabled={stocktake.lines.length === 0}
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
                        <TableHead className="w-40">Counted</TableHead>
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
    row: Ingredient;
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
                {money(row.onHand)}
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
                        inputMode="decimal"
                        value={form.data.counted_quantity}
                        onChange={(event) =>
                            form.setData('counted_quantity', event.target.value)
                        }
                    />
                    <Button
                        type="submit"
                        variant="outline"
                        size="sm"
                        disabled={
                            form.processing ||
                            form.data.counted_quantity.trim() === ''
                        }
                    >
                        Save
                    </Button>
                </form>
            </TableCell>
        </TableRow>
    );
}

function IngredientForm({
    row,
    onClose,
}: {
    row: Ingredient | null;
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
            variant="dialog"
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
            </Field>
            <div className="grid grid-cols-2 gap-3">
                <Field>
                    <FieldLabel htmlFor="ing-base">Base unit</FieldLabel>
                    <Input
                        id="ing-base"
                        maxLength={20}
                        value={form.data.base_unit}
                        onChange={(event) =>
                            form.setData('base_unit', event.target.value)
                        }
                    />
                </Field>
                <Field>
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
                </Field>
            </div>
            <div className="grid grid-cols-2 gap-3">
                <Field>
                    <FieldLabel htmlFor="ing-ratio">
                        Base units per purchase unit
                    </FieldLabel>
                    <Input
                        id="ing-ratio"
                        inputMode="decimal"
                        value={form.data.purchase_to_base_ratio}
                        onChange={(event) =>
                            form.setData(
                                'purchase_to_base_ratio',
                                event.target.value,
                            )
                        }
                    />
                </Field>
                <Field>
                    <FieldLabel htmlFor="ing-threshold">
                        Low stock at
                    </FieldLabel>
                    <Input
                        id="ing-threshold"
                        inputMode="decimal"
                        value={form.data.low_stock_threshold}
                        onChange={(event) =>
                            form.setData(
                                'low_stock_threshold',
                                event.target.value,
                            )
                        }
                    />
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

PosInventory.layout = {
    breadcrumbs: posSettingsBreadcrumbs('inventory'),
};
