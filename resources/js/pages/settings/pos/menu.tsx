import { Plus, UtensilsCrossed } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import type { EnumOption } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import {
    PosCategoryForm,
    PosItemForm,
    PosOutletOverrideSheet,
} from '@/components/pms/pos/pos-menu-forms';
import type {
    MenuCategory,
    MenuItem,
} from '@/components/pms/pos/pos-menu-forms';
import {
    PosSettingsShell,
    posSettingsBreadcrumbs,
} from '@/components/pms/pos/pos-settings-tabs';
import { categorySwatchClass } from '@/components/pms/pos/pos-types';
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
import { cn } from '@/lib/utils';

type Props = {
    currency: App.Data.Property.CurrencyData | null;
    outlets: EnumOption[];
    pricingTypes: EnumOption[];
    availabilityOptions: EnumOption[];
    categories: MenuCategory[];
    transactionCodes: EnumOption[];
    mealPeriods: EnumOption[];
};

export default function PosMenuSettings({
    currency,
    outlets,
    pricingTypes,
    availabilityOptions,
    categories,
    transactionCodes,
    mealPeriods,
}: Props) {
    const [category, setCategory] = useState<{
        row: MenuCategory | null;
    } | null>(null);
    const [item, setItem] = useState<{
        row: MenuItem | null;
        categoryId: number;
    } | null>(null);
    const [overrideItem, setOverrideItem] = useState<MenuItem | null>(null);

    return (
        <PosSettingsShell activeTab="menu">
            <div className="flex flex-col gap-6">
                <div>
                    <Button onClick={() => setCategory({ row: null })}>
                        <Plus data-icon="inline-start" />
                        Add category
                    </Button>
                </div>
                {categories.length === 0 ? (
                    <EmptyState
                        icon={UtensilsCrossed}
                        title="No menu categories yet"
                        description="Create a category (such as Food or Drinks) and add items with sizes and prices."
                    />
                ) : (
                    categories.map((row) => (
                        <Card key={row.id}>
                            <CardHeader className="flex flex-row items-start justify-between gap-4">
                                <div className="flex flex-col gap-1">
                                    <CardTitle className="flex items-center gap-2">
                                        <span
                                            aria-hidden
                                            className={cn(
                                                'size-3 rounded-full',
                                                categorySwatchClass(
                                                    row.colorToken,
                                                ),
                                            )}
                                        />
                                        {row.name}
                                    </CardTitle>
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
                                            <TableHead>
                                                Prices ({currency?.code ?? 'MYR'}
                                                )
                                            </TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead className="w-40">
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
                                                    <div className="flex flex-wrap gap-x-2 gap-y-0.5">
                                                        {entry.variants.map(
                                                            (variant, idx) => (
                                                                <span
                                                                    key={
                                                                        variant.id ??
                                                                        idx
                                                                    }
                                                                >
                                                                    {idx > 0 &&
                                                                        '· '}
                                                                    {
                                                                        variant.name
                                                                    }{' '}
                                                                    <MoneyText
                                                                        amount={
                                                                            variant.price
                                                                        }
                                                                        currency={
                                                                            currency
                                                                        }
                                                                        plain
                                                                    />
                                                                </span>
                                                            ),
                                                        )}
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant="secondary">
                                                        {entry.active
                                                            ? 'Active'
                                                            : 'Inactive'}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex justify-end gap-1">
                                                        {outlets.length > 0 && (
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() =>
                                                                    setOverrideItem(
                                                                        entry,
                                                                    )
                                                                }
                                                            >
                                                                Outlets
                                                            </Button>
                                                        )}
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() =>
                                                                setItem({
                                                                    row: entry,
                                                                    categoryId:
                                                                        row.id,
                                                                })
                                                            }
                                                        >
                                                            Edit
                                                        </Button>
                                                    </div>
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
                    ))
                )}
            </div>
            {category && (
                <PosCategoryForm
                    key={category.row?.id ?? 'new'}
                    row={category.row}
                    codes={transactionCodes}
                    onClose={() => setCategory(null)}
                />
            )}
            {item && (
                <PosItemForm
                    key={item.row?.id ?? 'new'}
                    row={item.row}
                    categoryId={item.categoryId}
                    codes={transactionCodes}
                    mealPeriods={mealPeriods}
                    pricingTypes={pricingTypes}
                    onClose={() => setItem(null)}
                />
            )}
            {overrideItem && outlets.length > 0 && (
                <PosOutletOverrideSheet
                    key={overrideItem.id}
                    item={overrideItem}
                    outlets={outlets}
                    availabilityOptions={availabilityOptions}
                    onClose={() => setOverrideItem(null)}
                />
            )}
        </PosSettingsShell>
    );
}

PosMenuSettings.layout = {
    breadcrumbs: posSettingsBreadcrumbs('menu'),
};
