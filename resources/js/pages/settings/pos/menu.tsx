import {
    Pencil,
    Plus,
    Search,
    SlidersHorizontal,
    UtensilsCrossed,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
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
import { StatusBadge } from '@/components/pms/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupButton,
    InputGroupInput,
} from '@/components/ui/input-group';
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

const STATUS_FILTER_OPTIONS: EnumOption[] = [
    { value: 'all', label: 'All statuses' },
    { value: 'active', label: 'Active only' },
    { value: 'inactive', label: 'Inactive only' },
];

function summarizeOutletOverrides(item: MenuItem): {
    label: string;
    tone: App.Enums.Tone;
} | null {
    const overrides = item.variants.flatMap(
        (variant) => variant.outletItems ?? [],
    );

    if (overrides.length === 0) {
        return null;
    }

    const unavailable = overrides.filter(
        (entry) => entry.availability === 'none',
    ).length;

    if (unavailable > 0) {
        return {
            label: `${unavailable} unavailable`,
            tone: 'warning',
        };
    }

    const posOnly = overrides.filter(
        (entry) => entry.availability === 'pos_only',
    ).length;
    const priceCount = overrides.filter(
        (entry) => entry.priceOverride !== null,
    ).length;

    if (priceCount > 0) {
        return {
            label: `${priceCount} custom ${priceCount === 1 ? 'price' : 'prices'}`,
            tone: 'info',
        };
    }

    if (posOnly > 0) {
        return {
            label: `${posOnly} POS only`,
            tone: 'neutral',
        };
    }

    return {
        label: `${overrides.length} ${overrides.length === 1 ? 'override' : 'overrides'}`,
        tone: 'neutral',
    };
}

function itemTags(
    item: MenuItem,
    mealPeriodNames: Map<string, string>,
): string[] {
    const tags: string[] = [];

    if (item.priceIncludesTax) {
        tags.push('Tax incl.');
    }

    if (item.serviceChargeExempt) {
        tags.push('No service charge');
    }

    if (item.soldByWeight) {
        tags.push(
            `By weight${item.weightUnit ? ` (${item.weightUnitDivisor ?? '1'} ${item.weightUnit})` : ''}`,
        );
    }

    if (item.sharedStockCount !== null) {
        tags.push(`Stock: ${item.sharedStockCount}`);
    }

    if (item.mealPeriodId !== null) {
        const mealName = mealPeriodNames.get(String(item.mealPeriodId));

        if (mealName) {
            tags.push(mealName);
        }
    }

    return tags;
}

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
    const [selectedCategoryId, setSelectedCategoryId] = useState<number | null>(
        null,
    );
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('all');

    const activeCategoryId =
        selectedCategoryId !== null &&
        categories.some((entry) => entry.id === selectedCategoryId)
            ? selectedCategoryId
            : null;

    const codeNames = useMemo(
        () => new Map(transactionCodes.map((code) => [code.value, code.label])),
        [transactionCodes],
    );
    const mealPeriodNames = useMemo(
        () =>
            new Map(mealPeriods.map((period) => [period.value, period.label])),
        [mealPeriods],
    );
    const categoryOptions = useMemo<EnumOption[]>(
        () =>
            categories.map((entry) => ({
                value: String(entry.id),
                label: entry.name,
            })),
        [categories],
    );

    const totalItems = useMemo(
        () => categories.reduce((sum, entry) => sum + entry.items.length, 0),
        [categories],
    );

    const hasActiveFilter = search.trim() !== '' || statusFilter !== 'all';

    const visibleCategories = useMemo(() => {
        const normalized = search.trim().toLocaleLowerCase();
        const scoped =
            activeCategoryId === null
                ? categories
                : categories.filter((entry) => entry.id === activeCategoryId);

        return scoped
            .map((entry) => {
                const filteredItems = entry.items.filter((menuItem) => {
                    if (statusFilter === 'active' && !menuItem.active) {
                        return false;
                    }

                    if (statusFilter === 'inactive' && menuItem.active) {
                        return false;
                    }

                    if (normalized === '') {
                        return true;
                    }

                    const haystack = [
                        entry.name,
                        menuItem.name,
                        menuItem.internalName ?? '',
                        menuItem.description ?? '',
                        ...menuItem.variants.flatMap((variant) => [
                            variant.name,
                            variant.sku ?? '',
                            variant.barcode ?? '',
                        ]),
                    ];

                    return haystack.some((value) =>
                        value.toLocaleLowerCase().includes(normalized),
                    );
                });

                return {
                    ...entry,
                    items: filteredItems,
                };
            })
            .filter(
                (entry) =>
                    !hasActiveFilter ||
                    entry.items.length > 0 ||
                    activeCategoryId === entry.id,
            );
    }, [categories, activeCategoryId, search, statusFilter, hasActiveFilter]);

    const visibleItemCount = useMemo(
        () =>
            visibleCategories.reduce(
                (sum, entry) => sum + entry.items.length,
                0,
            ),
        [visibleCategories],
    );

    const defaultAddCategoryId = activeCategoryId ?? categories[0]?.id ?? null;

    const headerActions = (
        <div className="flex flex-wrap items-center justify-end gap-2">
            {categories.length > 0 ? (
                <>
                    <Button
                        type="button"
                        variant="outline"
                        className="max-sm:min-h-11"
                        onClick={() => setCategory({ row: null })}
                    >
                        <Plus data-icon="inline-start" />
                        Add category
                    </Button>
                    {defaultAddCategoryId !== null && (
                        <Button
                            type="button"
                            className="max-sm:min-h-11"
                            onClick={() =>
                                setItem({
                                    row: null,
                                    categoryId: defaultAddCategoryId,
                                })
                            }
                        >
                            <Plus data-icon="inline-start" />
                            Add item
                        </Button>
                    )}
                </>
            ) : (
                <Button
                    type="button"
                    className="max-sm:min-h-11"
                    onClick={() => setCategory({ row: null })}
                >
                    <Plus data-icon="inline-start" />
                    Add category
                </Button>
            )}
        </div>
    );

    return (
        <PosSettingsShell activeTab="menu" actions={headerActions}>
            {categories.length === 0 ? (
                <div className="rounded-xl border bg-card p-6">
                    <EmptyState
                        icon={UtensilsCrossed}
                        title="No menu categories yet"
                        description="Use Add category above to create your first group, such as Food or Drinks, then add items with sizes and prices."
                    />
                </div>
            ) : (
                <div className="flex flex-col gap-5">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex flex-1 flex-col gap-2 sm:max-w-xl sm:flex-row sm:items-center">
                            <InputGroup className="w-full sm:flex-1">
                                <InputGroupAddon>
                                    <Search aria-hidden />
                                </InputGroupAddon>
                                <InputGroupInput
                                    type="search"
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                    placeholder="Search items, kitchen name, SKU, or barcode"
                                    aria-label="Search menu items"
                                />
                                {search !== '' && (
                                    <InputGroupAddon align="inline-end">
                                        <InputGroupButton
                                            size="icon-xs"
                                            aria-label="Clear search"
                                            onClick={() => setSearch('')}
                                        >
                                            <X />
                                        </InputGroupButton>
                                    </InputGroupAddon>
                                )}
                            </InputGroup>
                            <EnumSelect
                                name="status_filter"
                                options={STATUS_FILTER_OPTIONS}
                                value={statusFilter}
                                onValueChange={setStatusFilter}
                                icon={<SlidersHorizontal aria-hidden />}
                                aria-label="Filter items by status"
                                className="w-full sm:w-44"
                            />
                        </div>
                        <p className="text-sm text-muted-foreground tabular-nums">
                            {hasActiveFilter || activeCategoryId !== null
                                ? `Showing ${visibleItemCount} of ${totalItems} ${totalItems === 1 ? 'item' : 'items'}`
                                : `${categories.length} ${categories.length === 1 ? 'category' : 'categories'} · ${totalItems} ${totalItems === 1 ? 'item' : 'items'}`}
                        </p>
                    </div>

                    <div className="flex items-center gap-2 overflow-x-auto pb-1 lg:hidden">
                        <Button
                            type="button"
                            size="sm"
                            variant={
                                activeCategoryId === null
                                    ? 'default'
                                    : 'outline'
                            }
                            className="shrink-0 max-sm:min-h-11"
                            onClick={() => setSelectedCategoryId(null)}
                        >
                            All ({totalItems})
                        </Button>
                        {categories.map((entry) => {
                            const selected = activeCategoryId === entry.id;

                            return (
                                <Button
                                    key={entry.id}
                                    type="button"
                                    size="sm"
                                    variant={selected ? 'default' : 'outline'}
                                    className="shrink-0 max-sm:min-h-11"
                                    onClick={() =>
                                        setSelectedCategoryId(entry.id)
                                    }
                                >
                                    <span
                                        aria-hidden
                                        className={cn(
                                            'size-2.5 rounded-full',
                                            categorySwatchClass(
                                                entry.colorToken,
                                            ),
                                        )}
                                    />
                                    <span>{entry.name}</span>
                                    <span className="tabular-nums opacity-75">
                                        ({entry.items.length})
                                    </span>
                                </Button>
                            );
                        })}
                    </div>

                    <div className="grid grid-cols-1 items-start gap-6 lg:grid-cols-[16rem_minmax(0,1fr)]">
                        <aside
                            aria-label="Menu categories"
                            className="hidden rounded-xl border bg-card p-2 lg:sticky lg:top-4 lg:flex lg:flex-col lg:gap-1"
                        >
                            <div className="flex items-center justify-between px-2 py-1.5">
                                <span className="text-xs font-medium text-muted-foreground">
                                    Categories
                                </span>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-xs"
                                    aria-label="Add category"
                                    onClick={() => setCategory({ row: null })}
                                >
                                    <Plus />
                                </Button>
                            </div>
                            <button
                                type="button"
                                aria-pressed={activeCategoryId === null}
                                onClick={() => setSelectedCategoryId(null)}
                                className={cn(
                                    'flex w-full items-center justify-between gap-2 rounded-lg px-2.5 py-2 text-left text-sm transition-colors focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none motion-reduce:transition-none',
                                    activeCategoryId === null
                                        ? 'bg-muted font-medium text-foreground ring-1 ring-border'
                                        : 'text-muted-foreground hover:bg-muted/60 hover:text-foreground',
                                )}
                            >
                                <span className="truncate">All categories</span>
                                <span className="text-xs text-muted-foreground tabular-nums">
                                    {totalItems}
                                </span>
                            </button>
                            {categories.map((entry) => {
                                const selected = activeCategoryId === entry.id;

                                return (
                                    <div
                                        key={entry.id}
                                        className={cn(
                                            'group flex items-center gap-1 rounded-lg transition-colors motion-reduce:transition-none',
                                            selected
                                                ? 'bg-muted ring-1 ring-border'
                                                : 'hover:bg-muted/60',
                                        )}
                                    >
                                        <button
                                            type="button"
                                            aria-pressed={selected}
                                            onClick={() =>
                                                setSelectedCategoryId(entry.id)
                                            }
                                            className={cn(
                                                'flex min-w-0 flex-1 items-center gap-2.5 rounded-lg py-2 pr-1 pl-2.5 text-left text-sm focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none',
                                                selected
                                                    ? 'font-medium text-foreground'
                                                    : 'text-foreground/85',
                                            )}
                                        >
                                            <span
                                                aria-hidden
                                                className={cn(
                                                    'size-2.5 shrink-0 rounded-full',
                                                    categorySwatchClass(
                                                        entry.colorToken,
                                                    ),
                                                )}
                                            />
                                            <span className="min-w-0 flex-1 truncate">
                                                {entry.name}
                                            </span>
                                            {!entry.active && (
                                                <span className="text-xs text-muted-foreground">
                                                    Off
                                                </span>
                                            )}
                                            <span className="text-xs text-muted-foreground tabular-nums">
                                                {entry.items.length}
                                            </span>
                                        </button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon-xs"
                                            className="mr-1 shrink-0 text-muted-foreground hover:text-foreground"
                                            aria-label={`Edit category ${entry.name}`}
                                            onClick={() =>
                                                setCategory({ row: entry })
                                            }
                                        >
                                            <Pencil />
                                        </Button>
                                    </div>
                                );
                            })}
                        </aside>

                        <div className="flex min-w-0 flex-col gap-5">
                            {visibleCategories.length === 0 ||
                            (hasActiveFilter && visibleItemCount === 0) ? (
                                <div className="rounded-xl border bg-card p-6">
                                    <EmptyState
                                        icon={Search}
                                        title="No matching menu items"
                                        description="No items match the current search or status filter."
                                        action={
                                            <Button
                                                type="button"
                                                variant="outline"
                                                className="max-sm:min-h-11"
                                                onClick={() => {
                                                    setSearch('');
                                                    setStatusFilter('all');
                                                    setSelectedCategoryId(null);
                                                }}
                                            >
                                                Clear filters
                                            </Button>
                                        }
                                    />
                                </div>
                            ) : (
                                visibleCategories.map((row) => {
                                    const accountLabel = codeNames.get(
                                        String(row.transactionCodeId),
                                    );

                                    return (
                                        <section
                                            key={row.id}
                                            className="overflow-hidden rounded-xl border bg-card"
                                        >
                                            <header className="flex flex-wrap items-center justify-between gap-3 border-b bg-muted/40 px-4 py-3">
                                                <div className="flex min-w-0 flex-col gap-0.5">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span
                                                            aria-hidden
                                                            className={cn(
                                                                'size-3 shrink-0 rounded-full',
                                                                categorySwatchClass(
                                                                    row.colorToken,
                                                                ),
                                                            )}
                                                        />
                                                        <h2 className="truncate font-heading text-base font-medium">
                                                            {row.name}
                                                        </h2>
                                                        <Badge variant="outline">
                                                            <span className="tabular-nums">
                                                                {
                                                                    row.items
                                                                        .length
                                                                }{' '}
                                                                {row.items
                                                                    .length ===
                                                                1
                                                                    ? 'item'
                                                                    : 'items'}
                                                            </span>
                                                        </Badge>
                                                        {!row.active && (
                                                            <StatusBadge
                                                                status={{
                                                                    value: 'hidden',
                                                                    label: 'Hidden from register',
                                                                    tone: 'neutral',
                                                                }}
                                                            />
                                                        )}
                                                    </div>
                                                    {(row.active ||
                                                        accountLabel) && (
                                                        <p className="text-xs text-muted-foreground">
                                                            {row.active
                                                                ? `Shown on register${accountLabel ? ` · Account: ${accountLabel}` : ''}`
                                                                : `Account: ${accountLabel}`}
                                                        </p>
                                                    )}
                                                </div>
                                                <div className="ml-auto flex flex-wrap items-center justify-end gap-2">
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        className="max-sm:min-h-11"
                                                        aria-label={`Edit category ${row.name}`}
                                                        onClick={() =>
                                                            setCategory({ row })
                                                        }
                                                    >
                                                        Edit
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        variant="secondary"
                                                        size="sm"
                                                        className="max-sm:min-h-11"
                                                        aria-label={`Add item to ${row.name}`}
                                                        onClick={() =>
                                                            setItem({
                                                                row: null,
                                                                categoryId:
                                                                    row.id,
                                                            })
                                                        }
                                                    >
                                                        <Plus data-icon="inline-start" />
                                                        Add item
                                                    </Button>
                                                </div>
                                            </header>

                                            {row.items.length === 0 ? (
                                                <div className="p-6">
                                                    <EmptyState
                                                        icon={UtensilsCrossed}
                                                        title={
                                                            hasActiveFilter
                                                                ? 'No matching items in this category'
                                                                : `No items in ${row.name} yet`
                                                        }
                                                        description={
                                                            hasActiveFilter
                                                                ? 'Try clearing your search or status filter.'
                                                                : 'Add your first item with sizes, prices, and kitchen details.'
                                                        }
                                                        action={
                                                            hasActiveFilter ? (
                                                                <Button
                                                                    type="button"
                                                                    variant="outline"
                                                                    size="sm"
                                                                    className="max-sm:min-h-11"
                                                                    onClick={() => {
                                                                        setSearch(
                                                                            '',
                                                                        );
                                                                        setStatusFilter(
                                                                            'all',
                                                                        );
                                                                    }}
                                                                >
                                                                    Clear
                                                                    filters
                                                                </Button>
                                                            ) : undefined
                                                        }
                                                    />
                                                </div>
                                            ) : (
                                                <>
                                                    <div className="hidden md:block">
                                                        <Table>
                                                            <TableHeader>
                                                                <TableRow className="hover:bg-transparent">
                                                                    <TableHead scope="col">
                                                                        Item
                                                                    </TableHead>
                                                                    <TableHead scope="col">
                                                                        Prices (
                                                                        {currency?.code ??
                                                                            'MYR'}
                                                                        )
                                                                    </TableHead>
                                                                    {outlets.length >
                                                                        0 && (
                                                                        <TableHead scope="col">
                                                                            Outlets
                                                                        </TableHead>
                                                                    )}
                                                                    <TableHead scope="col">
                                                                        Status
                                                                    </TableHead>
                                                                    <TableHead
                                                                        scope="col"
                                                                        className="w-40 text-right"
                                                                    >
                                                                        <span className="sr-only">
                                                                            Actions
                                                                        </span>
                                                                    </TableHead>
                                                                </TableRow>
                                                            </TableHeader>
                                                            <TableBody>
                                                                {row.items.map(
                                                                    (entry) => {
                                                                        const tags =
                                                                            itemTags(
                                                                                entry,
                                                                                mealPeriodNames,
                                                                            );
                                                                        const outletSummary =
                                                                            summarizeOutletOverrides(
                                                                                entry,
                                                                            );

                                                                        return (
                                                                            <TableRow
                                                                                key={
                                                                                    entry.id
                                                                                }
                                                                            >
                                                                                <TableCell className="max-w-xs whitespace-normal">
                                                                                    <div className="flex flex-col gap-0.5">
                                                                                        <span className="font-medium text-foreground">
                                                                                            {
                                                                                                entry.name
                                                                                            }
                                                                                        </span>
                                                                                        {entry.internalName && (
                                                                                            <span className="text-xs text-muted-foreground">
                                                                                                Kitchen:{' '}
                                                                                                {
                                                                                                    entry.internalName
                                                                                                }
                                                                                            </span>
                                                                                        )}
                                                                                        {entry.description && (
                                                                                            <span className="line-clamp-1 text-xs text-muted-foreground">
                                                                                                {
                                                                                                    entry.description
                                                                                                }
                                                                                            </span>
                                                                                        )}
                                                                                        {tags.length >
                                                                                            0 && (
                                                                                            <div className="mt-1 flex flex-wrap gap-1">
                                                                                                {tags.map(
                                                                                                    (
                                                                                                        tag,
                                                                                                    ) => (
                                                                                                        <Badge
                                                                                                            key={
                                                                                                                tag
                                                                                                            }
                                                                                                            variant="outline"
                                                                                                            className="font-normal text-muted-foreground"
                                                                                                        >
                                                                                                            {
                                                                                                                tag
                                                                                                            }
                                                                                                        </Badge>
                                                                                                    ),
                                                                                                )}
                                                                                            </div>
                                                                                        )}
                                                                                    </div>
                                                                                </TableCell>
                                                                                <TableCell className="whitespace-normal tabular-nums">
                                                                                    <div className="flex flex-col gap-1">
                                                                                        {entry.variants.map(
                                                                                            (
                                                                                                variant,
                                                                                                idx,
                                                                                            ) => (
                                                                                                <div
                                                                                                    key={
                                                                                                        variant.id ??
                                                                                                        idx
                                                                                                    }
                                                                                                    className={cn(
                                                                                                        'flex flex-wrap items-baseline gap-x-2 text-sm',
                                                                                                        !variant.active &&
                                                                                                            'text-muted-foreground line-through',
                                                                                                    )}
                                                                                                >
                                                                                                    <span className="text-muted-foreground">
                                                                                                        {
                                                                                                            variant.name
                                                                                                        }
                                                                                                    </span>
                                                                                                    <span className="font-medium text-foreground">
                                                                                                        {variant.pricingType ===
                                                                                                        'variable' ? (
                                                                                                            'Variable'
                                                                                                        ) : (
                                                                                                            <MoneyText
                                                                                                                amount={
                                                                                                                    variant.price
                                                                                                                }
                                                                                                                currency={
                                                                                                                    currency
                                                                                                                }
                                                                                                                plain
                                                                                                            />
                                                                                                        )}
                                                                                                    </span>
                                                                                                    {variant.sku && (
                                                                                                        <span className="font-mono text-xs text-muted-foreground">
                                                                                                            {
                                                                                                                variant.sku
                                                                                                            }
                                                                                                        </span>
                                                                                                    )}
                                                                                                </div>
                                                                                            ),
                                                                                        )}
                                                                                    </div>
                                                                                </TableCell>
                                                                                {outlets.length >
                                                                                    0 && (
                                                                                    <TableCell>
                                                                                        {outletSummary ? (
                                                                                            <StatusBadge
                                                                                                status={{
                                                                                                    value: outletSummary.label,
                                                                                                    label: outletSummary.label,
                                                                                                    tone: outletSummary.tone,
                                                                                                }}
                                                                                                dot={
                                                                                                    outletSummary.tone ===
                                                                                                    'warning'
                                                                                                }
                                                                                            />
                                                                                        ) : (
                                                                                            <span className="text-xs text-muted-foreground">
                                                                                                Default
                                                                                            </span>
                                                                                        )}
                                                                                    </TableCell>
                                                                                )}
                                                                                <TableCell>
                                                                                    <StatusBadge
                                                                                        status={{
                                                                                            value: entry.active
                                                                                                ? 'active'
                                                                                                : 'inactive',
                                                                                            label: entry.active
                                                                                                ? 'Active'
                                                                                                : 'Inactive',
                                                                                            tone: entry.active
                                                                                                ? 'success'
                                                                                                : 'neutral',
                                                                                        }}
                                                                                    />
                                                                                </TableCell>
                                                                                <TableCell>
                                                                                    <div className="flex justify-end gap-1.5">
                                                                                        {outlets.length >
                                                                                            0 && (
                                                                                            <Button
                                                                                                type="button"
                                                                                                variant="ghost"
                                                                                                size="sm"
                                                                                                aria-label={`Outlets for ${entry.name}`}
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
                                                                                            type="button"
                                                                                            variant="outline"
                                                                                            size="sm"
                                                                                            aria-label={`Edit ${entry.name}`}
                                                                                            onClick={() =>
                                                                                                setItem(
                                                                                                    {
                                                                                                        row: entry,
                                                                                                        categoryId:
                                                                                                            row.id,
                                                                                                    },
                                                                                                )
                                                                                            }
                                                                                        >
                                                                                            Edit
                                                                                        </Button>
                                                                                    </div>
                                                                                </TableCell>
                                                                            </TableRow>
                                                                        );
                                                                    },
                                                                )}
                                                            </TableBody>
                                                        </Table>
                                                    </div>

                                                    <div className="flex flex-col divide-y md:hidden">
                                                        {row.items.map(
                                                            (entry) => {
                                                                const tags =
                                                                    itemTags(
                                                                        entry,
                                                                        mealPeriodNames,
                                                                    );
                                                                const outletSummary =
                                                                    summarizeOutletOverrides(
                                                                        entry,
                                                                    );

                                                                return (
                                                                    <div
                                                                        key={
                                                                            entry.id
                                                                        }
                                                                        className="flex flex-col gap-3 p-4"
                                                                    >
                                                                        <div className="flex items-start justify-between gap-3">
                                                                            <div className="flex min-w-0 flex-col gap-0.5">
                                                                                <span className="font-medium text-foreground">
                                                                                    {
                                                                                        entry.name
                                                                                    }
                                                                                </span>
                                                                                {entry.internalName && (
                                                                                    <span className="text-xs text-muted-foreground">
                                                                                        Kitchen:{' '}
                                                                                        {
                                                                                            entry.internalName
                                                                                        }
                                                                                    </span>
                                                                                )}
                                                                            </div>
                                                                            <StatusBadge
                                                                                status={{
                                                                                    value: entry.active
                                                                                        ? 'active'
                                                                                        : 'inactive',
                                                                                    label: entry.active
                                                                                        ? 'Active'
                                                                                        : 'Inactive',
                                                                                    tone: entry.active
                                                                                        ? 'success'
                                                                                        : 'neutral',
                                                                                }}
                                                                            />
                                                                        </div>
                                                                        <div className="flex flex-wrap gap-x-3 gap-y-1 text-sm tabular-nums">
                                                                            {entry.variants.map(
                                                                                (
                                                                                    variant,
                                                                                    idx,
                                                                                ) => (
                                                                                    <span
                                                                                        key={
                                                                                            variant.id ??
                                                                                            idx
                                                                                        }
                                                                                        className="text-foreground"
                                                                                    >
                                                                                        <span className="text-muted-foreground">
                                                                                            {
                                                                                                variant.name
                                                                                            }
                                                                                            :{' '}
                                                                                        </span>
                                                                                        {variant.pricingType ===
                                                                                        'variable' ? (
                                                                                            'Variable'
                                                                                        ) : (
                                                                                            <MoneyText
                                                                                                amount={
                                                                                                    variant.price
                                                                                                }
                                                                                                currency={
                                                                                                    currency
                                                                                                }
                                                                                            />
                                                                                        )}
                                                                                    </span>
                                                                                ),
                                                                            )}
                                                                        </div>
                                                                        {(tags.length >
                                                                            0 ||
                                                                            outletSummary) && (
                                                                            <div className="flex flex-wrap items-center gap-1.5">
                                                                                {outletSummary && (
                                                                                    <StatusBadge
                                                                                        status={{
                                                                                            value: outletSummary.label,
                                                                                            label: outletSummary.label,
                                                                                            tone: outletSummary.tone,
                                                                                        }}
                                                                                        dot={
                                                                                            outletSummary.tone ===
                                                                                            'warning'
                                                                                        }
                                                                                    />
                                                                                )}
                                                                                {tags.map(
                                                                                    (
                                                                                        tag,
                                                                                    ) => (
                                                                                        <Badge
                                                                                            key={
                                                                                                tag
                                                                                            }
                                                                                            variant="outline"
                                                                                            className="font-normal text-muted-foreground"
                                                                                        >
                                                                                            {
                                                                                                tag
                                                                                            }
                                                                                        </Badge>
                                                                                    ),
                                                                                )}
                                                                            </div>
                                                                        )}
                                                                        <div className="flex justify-end gap-2">
                                                                            {outlets.length >
                                                                                0 && (
                                                                                <Button
                                                                                    type="button"
                                                                                    variant="ghost"
                                                                                    className="min-h-11"
                                                                                    aria-label={`Outlets for ${entry.name}`}
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
                                                                                type="button"
                                                                                variant="outline"
                                                                                className="min-h-11"
                                                                                aria-label={`Edit ${entry.name}`}
                                                                                onClick={() =>
                                                                                    setItem(
                                                                                        {
                                                                                            row: entry,
                                                                                            categoryId:
                                                                                                row.id,
                                                                                        },
                                                                                    )
                                                                                }
                                                                            >
                                                                                Edit
                                                                            </Button>
                                                                        </div>
                                                                    </div>
                                                                );
                                                            },
                                                        )}
                                                    </div>
                                                </>
                                            )}
                                        </section>
                                    );
                                })
                            )}
                        </div>
                    </div>
                </div>
            )}

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
                    key={item.row?.id ?? `new-${item.categoryId}`}
                    row={item.row}
                    categoryId={item.categoryId}
                    categories={categoryOptions}
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
