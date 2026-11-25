import { router } from '@inertiajs/react';
import { Package, Plus } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import {
    PosIngredientForm,
    PosReceiveForm,
    PosRecipeRow,
    PosStocktakePanel,
    PosTransferForm,
    PosWasteForm,
} from '@/components/pms/pos/pos-inventory-panels';
import type {
    IngredientRow,
    StocktakeRow,
    VariantRecipeRow,
} from '@/components/pms/pos/pos-inventory-panels';
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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { index as inventoryIndex } from '@/routes/settings/pos/inventory';

type Props = {
    currency: App.Data.Property.CurrencyData | null;
    outlets: EnumOption[];
    outlet: { id: number; name: string } | null;
    ingredients: IngredientRow[];
    variants: VariantRecipeRow[];
    stocktake: StocktakeRow | null;
    movementKinds: EnumOption[];
};

export default function PosInventory({
    currency,
    outlets,
    outlet,
    ingredients,
    variants,
    stocktake,
}: Props) {
    const [editing, setEditing] = useState<IngredientRow | 'new' | null>(null);
    const currencyCode = currency?.code ?? 'MYR';

    const ingredientOptions: EnumOption[] = ingredients.map((row) => ({
        value: String(row.id),
        label: `${row.name} (${row.baseUnit})`,
    }));

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
                    {ingredients.length === 0 ? (
                        <EmptyState
                            icon={Package}
                            title="No ingredients yet"
                            description="Add raw ingredients to track recipes, deliveries, waste, and closing counts."
                        />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Base unit</TableHead>
                                    <TableHead>Purchase unit</TableHead>
                                    <TableHead className="text-end">
                                        Unit cost ({currencyCode})
                                    </TableHead>
                                    <TableHead className="text-end">
                                        On hand
                                        {outlet !== null
                                            ? ` · ${outlet.name}`
                                            : ''}
                                    </TableHead>
                                    <TableHead>
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {ingredients.map((row) => (
                                    <TableRow key={row.id}>
                                        <TableCell className="font-medium">
                                            {row.name}{' '}
                                            {!row.active && (
                                                <Badge variant="outline">
                                                    Off
                                                </Badge>
                                            )}
                                        </TableCell>
                                        <TableCell>{row.baseUnit}</TableCell>
                                        <TableCell>
                                            {row.purchaseUnit !== null ? (
                                                <>
                                                    {row.purchaseUnit}
                                                    <span className="text-muted-foreground">
                                                        {' '}
                                                        · {row.ratio}{' '}
                                                        {row.baseUnit}
                                                    </span>
                                                </>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    Same as base
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-end tabular-nums">
                                            {Number(row.unitCost).toFixed(4)}
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
                    )}
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
                <CardContent className="flex flex-col gap-3">
                    {variants.map((variant) => (
                        <PosRecipeRow
                            key={variant.id}
                            variant={variant}
                            ingredients={ingredients}
                        />
                    ))}
                    {variants.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            No menu items yet.
                        </p>
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
                        {outlets.length > 1 && (
                            <EnumSelect
                                name="outlet"
                                options={outlets}
                                value={String(outlet.id)}
                                onValueChange={(value) =>
                                    router.get(
                                        inventoryIndex.url(undefined, {
                                            query: { outlet: value },
                                        }),
                                    )
                                }
                                aria-label="Outlet"
                            />
                        )}
                    </CardHeader>
                    <CardContent className="flex flex-col gap-6">
                        <div className="grid gap-4 md:grid-cols-3">
                            <PosReceiveForm
                                outletId={outlet.id}
                                ingredients={ingredientOptions}
                                currencyCode={currencyCode}
                            />
                            <PosWasteForm
                                outletId={outlet.id}
                                ingredients={ingredientOptions}
                            />
                            <PosTransferForm
                                fromOutletId={outlet.id}
                                outlets={outlets}
                                ingredients={ingredientOptions}
                            />
                        </div>
                        <PosStocktakePanel
                            outletId={outlet.id}
                            outletName={outlet.name}
                            ingredients={ingredients}
                            stocktake={stocktake}
                        />
                    </CardContent>
                </Card>
            )}

            {editing !== null && (
                <PosIngredientForm
                    row={editing === 'new' ? null : editing}
                    onClose={() => setEditing(null)}
                />
            )}
        </PosSettingsShell>
    );
}

PosInventory.layout = {
    breadcrumbs: posSettingsBreadcrumbs('inventory'),
};
