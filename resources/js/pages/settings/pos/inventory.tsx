import { router } from '@inertiajs/react';
import {
    ArrowLeftRight,
    BookOpen,
    ClipboardCheck,
    Package,
    PackagePlus,
    Plus,
    Search,
    Store,
    TriangleAlert,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import {
    PosIngredientForm,
    PosRecipeRow,
    PosStockMovementWorkbench,
    PosStocktakePanel,
    formatQty,
    formatRatio,
} from '@/components/pms/pos/pos-inventory-panels';
import type {
    IngredientRow,
    MovementMode,
    StocktakeRow,
    VariantRecipeRow,
} from '@/components/pms/pos/pos-inventory-panels';
import {
    PosSettingsShell,
    posSettingsBreadcrumbs,
} from '@/components/pms/pos/pos-settings-tabs';
import { StatusBadge } from '@/components/pms/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupButton,
    InputGroupInput,
} from '@/components/ui/input-group';
import { Kbd } from '@/components/ui/kbd';
import { Progress } from '@/components/ui/progress';
import { cn } from '@/lib/utils';
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

type InventoryTab = 'ingredients' | 'recipes' | 'movements' | 'stocktake';

function isLowStock(row: IngredientRow): boolean {
    return (
        row.active &&
        row.threshold !== null &&
        Number(row.onHand) <= Number(row.threshold)
    );
}

/** Radial recipe-costing / stock-health ring for the right-hand stockroom summary rail. */
function StockHealthRing({
    healthyCount,
    totalActive,
}: {
    healthyCount: number;
    totalActive: number;
}) {
    const percent =
        totalActive > 0 ? Math.round((healthyCount / totalActive) * 100) : 100;
    const radius = 38;
    const circumference = 2 * Math.PI * radius;
    const offset =
        totalActive === 0
            ? circumference
            : circumference - (percent / 100) * circumference;

    return (
        <div className="relative flex size-24 shrink-0 items-center justify-center">
            <svg
                viewBox="0 0 96 96"
                className="size-full -rotate-90"
                role="img"
                aria-label={`${healthyCount} of ${totalActive} active ingredients healthy`}
            >
                <circle
                    cx="48"
                    cy="48"
                    r={radius}
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="8"
                    className="text-muted"
                />
                <circle
                    cx="48"
                    cy="48"
                    r={radius}
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="8"
                    strokeLinecap="round"
                    strokeDasharray={circumference}
                    strokeDashoffset={offset}
                    className="text-primary transition-[stroke-dashoffset] duration-300 motion-reduce:transition-none"
                />
            </svg>
            <div className="absolute inset-0 flex flex-col items-center justify-center text-center">
                <span className="font-heading text-lg leading-none font-bold text-foreground tabular-nums">
                    {totalActive === 0 ? '0%' : `${percent}%`}
                </span>
                <span className="mt-0.5 text-[11px] text-muted-foreground">
                    in stock
                </span>
            </div>
        </div>
    );
}

export default function PosInventory({
    currency,
    outlets,
    outlet,
    ingredients,
    variants,
    stocktake,
}: Props) {
    const [section, setSection] = useState<InventoryTab>('ingredients');
    const [movementMode, setMovementMode] = useState<MovementMode>('receive');
    const [editing, setEditing] = useState<IngredientRow | 'new' | null>(null);
    const [ingredientSearch, setIngredientSearch] = useState('');
    const [ingredientFilter, setIngredientFilter] = useState<
        'all' | 'low' | 'inactive'
    >('all');
    const [recipeSearch, setRecipeSearch] = useState('');
    const [recipeFilter, setRecipeFilter] = useState<
        'all' | 'linked' | 'unlinked'
    >('all');
    const searchInputRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        const handleSlash = (event: KeyboardEvent) => {
            if (
                event.key !== '/' ||
                event.metaKey ||
                event.ctrlKey ||
                event.altKey
            ) {
                return;
            }

            const target = event.target as HTMLElement | null;

            if (
                target &&
                (target.tagName === 'INPUT' ||
                    target.tagName === 'TEXTAREA' ||
                    target.tagName === 'SELECT' ||
                    target.isContentEditable)
            ) {
                return;
            }

            if (searchInputRef.current) {
                event.preventDefault();
                searchInputRef.current.focus();
            }
        };

        window.addEventListener('keydown', handleSlash);

        return () => window.removeEventListener('keydown', handleSlash);
    }, []);

    const currencyCode = currency?.code ?? 'MYR';

    const stats = useMemo(() => {
        let activeCount = 0;
        let lowStockCount = 0;
        let inactiveCount = 0;
        let totalStockValue = 0;

        for (const row of ingredients) {
            if (!row.active) {
                inactiveCount += 1;
            } else {
                activeCount += 1;

                if (isLowStock(row)) {
                    lowStockCount += 1;
                }
            }

            const onHand = Number(row.onHand);
            const cost = Number(row.unitCost);

            if (
                Number.isFinite(onHand) &&
                Number.isFinite(cost) &&
                onHand > 0
            ) {
                totalStockValue += onHand * cost;
            }
        }

        const linkedRecipeCount = variants.filter(
            (variant) => variant.recipe.length > 0,
        ).length;
        const recipeCoveragePct =
            variants.length > 0
                ? Math.round((linkedRecipeCount / variants.length) * 100)
                : 0;

        return {
            activeCount,
            healthyCount: activeCount - lowStockCount,
            lowStockCount,
            inactiveCount,
            totalStockValue,
            linkedRecipeCount,
            recipeCoveragePct,
        };
    }, [ingredients, variants]);

    const ingredientOptions: EnumOption[] = useMemo(
        () =>
            ingredients.map((row) => ({
                value: String(row.id),
                label: `${row.name} (${row.baseUnit})`,
            })),
        [ingredients],
    );

    const filteredIngredients = useMemo(() => {
        const needle = ingredientSearch.trim().toLocaleLowerCase();

        return ingredients.filter((row) => {
            if (ingredientFilter === 'low' && !isLowStock(row)) {
                return false;
            }

            if (ingredientFilter === 'inactive' && row.active) {
                return false;
            }

            if (needle === '') {
                return true;
            }

            return `${row.name} ${row.sku ?? ''} ${row.baseUnit} ${row.purchaseUnit ?? ''}`
                .toLocaleLowerCase()
                .includes(needle);
        });
    }, [ingredients, ingredientFilter, ingredientSearch]);

    const filteredVariants = useMemo(() => {
        const needle = recipeSearch.trim().toLocaleLowerCase();

        return variants.filter((variant) => {
            if (recipeFilter === 'linked' && variant.recipe.length === 0) {
                return false;
            }

            if (recipeFilter === 'unlinked' && variant.recipe.length > 0) {
                return false;
            }

            if (needle === '') {
                return true;
            }

            return variant.name.toLocaleLowerCase().includes(needle);
        });
    }, [variants, recipeFilter, recipeSearch]);

    const navTabs: {
        value: InventoryTab;
        label: string;
        icon: LucideIcon;
        badge?: string;
        attention?: boolean;
    }[] = [
        {
            value: 'ingredients',
            label: 'Ingredients',
            icon: Package,
            badge: String(ingredients.length),
            attention: stats.lowStockCount > 0,
        },
        {
            value: 'recipes',
            label: 'Recipes',
            icon: BookOpen,
            badge: `${stats.linkedRecipeCount}/${variants.length}`,
        },
        ...(outlet !== null
            ? [
                  {
                      value: 'movements' as const,
                      label: 'Stock movements',
                      icon: ArrowLeftRight,
                  },
                  {
                      value: 'stocktake' as const,
                      label: 'Closing count',
                      icon: ClipboardCheck,
                      badge:
                          stocktake !== null
                              ? `${stocktake.lines.length}/${ingredients.length}`
                              : undefined,
                      attention: stocktake !== null,
                  },
              ]
            : []),
    ];

    const headerActions = (
        <>
            {outlet !== null && outlets.length > 1 && (
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
                    icon={<Store aria-hidden />}
                    aria-label="Outlet"
                    className="w-48"
                />
            )}
            <Button
                type="button"
                className="max-sm:min-h-11"
                onClick={() => setEditing('new')}
            >
                <Plus data-icon="inline-start" />
                Add ingredient
            </Button>
        </>
    );

    const countedPct =
        stocktake !== null && ingredients.length > 0
            ? Math.round((stocktake.lines.length / ingredients.length) * 100)
            : 0;

    return (
        <PosSettingsShell activeTab="inventory" actions={headerActions}>
            <nav
                aria-label="Inventory sections"
                className="flex items-center justify-between gap-3 border-b"
            >
                <div
                    role="tablist"
                    aria-label="Inventory sections"
                    className="-mb-px flex max-w-full items-center gap-1 overflow-x-auto"
                >
                    {navTabs.map((item) => {
                        const Icon = item.icon;
                        const active = section === item.value;

                        return (
                            <button
                                key={item.value}
                                type="button"
                                role="tab"
                                aria-selected={active}
                                onClick={() => setSection(item.value)}
                                className={cn(
                                    'inline-flex shrink-0 items-center gap-2 border-b-2 px-3.5 py-2.5 text-sm font-medium transition-colors focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none motion-reduce:transition-none max-sm:min-h-11',
                                    active
                                        ? 'border-primary text-foreground'
                                        : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground',
                                )}
                            >
                                <Icon
                                    aria-hidden="true"
                                    className="size-4 shrink-0"
                                />
                                <span>{item.label}</span>
                                {item.badge !== undefined && (
                                    <span
                                        className={cn(
                                            'inline-flex items-center rounded-full px-2 py-0.5 text-xs tabular-nums',
                                            item.attention
                                                ? 'bg-warning/15 font-medium text-warning-text'
                                                : active
                                                  ? 'bg-muted font-medium text-foreground'
                                                  : 'bg-muted/70 text-muted-foreground',
                                        )}
                                    >
                                        {item.badge}
                                    </span>
                                )}
                            </button>
                        );
                    })}
                </div>

                {stats.lowStockCount > 0 && (
                    <Button
                        type="button"
                        variant="outline"
                        size="xs"
                        className="mb-1.5 hidden shrink-0 border-warning/40 bg-warning/10 text-warning-text hover:bg-warning/15 sm:inline-flex"
                        onClick={() => {
                            setSection('ingredients');
                            setIngredientFilter('low');
                        }}
                    >
                        <TriangleAlert data-icon="inline-start" />
                        {stats.lowStockCount} low stock
                    </Button>
                )}
            </nav>

            <div className="grid grid-cols-1 items-start gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <div className="flex min-w-0 flex-col gap-4">
                    {section === 'ingredients' && (
                        <section
                            aria-label="Ingredients inventory"
                            className="@container overflow-hidden rounded-xl border bg-card"
                        >
                            <header className="flex flex-col gap-3 border-b bg-muted/40 px-4 py-3 @xl:flex-row @xl:items-center @xl:justify-between">
                                <InputGroup className="w-full @xl:max-w-xs">
                                    <InputGroupAddon>
                                        <Search aria-hidden />
                                    </InputGroupAddon>
                                    <InputGroupInput
                                        ref={searchInputRef}
                                        type="search"
                                        aria-label="Search ingredients"
                                        placeholder="Name, SKU, or unit"
                                        value={ingredientSearch}
                                        onChange={(event) =>
                                            setIngredientSearch(
                                                event.target.value,
                                            )
                                        }
                                    />
                                    {ingredientSearch !== '' ? (
                                        <InputGroupAddon align="inline-end">
                                            <InputGroupButton
                                                size="icon-xs"
                                                aria-label="Clear ingredient search"
                                                onClick={() =>
                                                    setIngredientSearch('')
                                                }
                                            >
                                                <X />
                                            </InputGroupButton>
                                        </InputGroupAddon>
                                    ) : (
                                        <InputGroupAddon
                                            align="inline-end"
                                            className="hidden sm:flex"
                                        >
                                            <Kbd>/</Kbd>
                                        </InputGroupAddon>
                                    )}
                                </InputGroup>

                                <div
                                    role="group"
                                    aria-label="Filter ingredients"
                                    className="flex flex-wrap items-center gap-1"
                                >
                                    {(
                                        [
                                            {
                                                value: 'all',
                                                label: 'All',
                                                count: ingredients.length,
                                            },
                                            {
                                                value: 'low',
                                                label: 'Low stock',
                                                count: stats.lowStockCount,
                                            },
                                            {
                                                value: 'inactive',
                                                label: 'Inactive',
                                                count: stats.inactiveCount,
                                            },
                                        ] as const
                                    ).map((item) => (
                                        <Button
                                            key={item.value}
                                            type="button"
                                            size="xs"
                                            variant={
                                                ingredientFilter === item.value
                                                    ? 'secondary'
                                                    : 'ghost'
                                            }
                                            aria-pressed={
                                                ingredientFilter === item.value
                                            }
                                            className="gap-1.5 tabular-nums"
                                            onClick={() =>
                                                setIngredientFilter(item.value)
                                            }
                                        >
                                            <span>{item.label}</span>
                                            <span className="text-muted-foreground">
                                                {item.count}
                                            </span>
                                        </Button>
                                    ))}
                                </div>
                            </header>

                            {ingredients.length === 0 ? (
                                <div className="p-8">
                                    <EmptyState
                                        icon={Package}
                                        title="No ingredients yet"
                                        description="Use Add ingredient above to track raw stock, dish recipes, deliveries, waste, and closing counts."
                                    />
                                </div>
                            ) : filteredIngredients.length === 0 ? (
                                <div className="p-8">
                                    <EmptyState
                                        icon={Search}
                                        title="No matching ingredients"
                                        description="No ingredient matches your current search or filter."
                                        action={
                                            <Button
                                                type="button"
                                                variant="outline"
                                                onClick={() => {
                                                    setIngredientSearch('');
                                                    setIngredientFilter('all');
                                                }}
                                            >
                                                Clear filters
                                            </Button>
                                        }
                                    />
                                </div>
                            ) : (
                                <div className="divide-y">
                                    {filteredIngredients.map((row) => {
                                        const low = isLowStock(row);
                                        const stockValue =
                                            Math.max(0, Number(row.onHand)) *
                                            Number(row.unitCost);
                                        const status = !row.active
                                            ? {
                                                  value: 'inactive',
                                                  label: 'Inactive',
                                                  tone: 'neutral' as const,
                                              }
                                            : low
                                              ? {
                                                    value: 'low',
                                                    label: 'Low stock',
                                                    tone: 'warning' as const,
                                                }
                                              : {
                                                    value: 'active',
                                                    label: 'In stock',
                                                    tone: 'success' as const,
                                                };

                                        return (
                                            <div
                                                key={row.id}
                                                className="flex flex-col gap-3 px-4 py-3.5 transition-colors hover:bg-muted/25 motion-reduce:transition-none @xl:flex-row @xl:items-center @xl:justify-between"
                                            >
                                                <div className="flex min-w-0 items-center gap-3.5">
                                                    <div
                                                        className={cn(
                                                            'flex size-12 shrink-0 flex-col items-center justify-center rounded-xl border font-heading text-xs font-bold',
                                                            !row.active
                                                                ? 'border-border bg-muted/40 text-muted-foreground'
                                                                : low
                                                                  ? 'border-warning/40 bg-warning/10 text-warning-text'
                                                                  : 'border-border bg-muted/60 text-foreground',
                                                        )}
                                                    >
                                                        <span className="truncate px-1">
                                                            {row.baseUnit}
                                                        </span>
                                                    </div>

                                                    <div className="flex min-w-0 flex-col gap-1">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <span className="truncate font-medium text-foreground">
                                                                {row.name}
                                                            </span>
                                                            <StatusBadge
                                                                status={status}
                                                            />
                                                        </div>
                                                        <div className="flex flex-col gap-0.5 text-xs text-muted-foreground tabular-nums @xl:flex-row @xl:flex-wrap @xl:items-center @xl:gap-x-2">
                                                            <span>
                                                                {row.purchaseUnit !==
                                                                null
                                                                    ? `1 ${row.purchaseUnit} = ${formatRatio(row.ratio)} ${row.baseUnit}`
                                                                    : `Tracked in ${row.baseUnit}`}
                                                            </span>
                                                            <span
                                                                aria-hidden="true"
                                                                className="hidden text-muted-foreground/50 @xl:inline"
                                                            >
                                                                ·
                                                            </span>
                                                            <span>
                                                                {currencyCode}{' '}
                                                                {Number(
                                                                    row.unitCost,
                                                                ).toFixed(
                                                                    4,
                                                                )}{' '}
                                                                / {row.baseUnit}
                                                            </span>
                                                            {row.sku && (
                                                                <>
                                                                    <span
                                                                        aria-hidden="true"
                                                                        className="hidden text-muted-foreground/50 @xl:inline"
                                                                    >
                                                                        ·
                                                                    </span>
                                                                    <span className="font-mono">
                                                                        {
                                                                            row.sku
                                                                        }
                                                                    </span>
                                                                </>
                                                            )}
                                                        </div>
                                                    </div>
                                                </div>

                                                <div className="flex flex-wrap items-center justify-between gap-4 @xl:justify-end">
                                                    <div className="flex flex-col @xl:items-end @xl:text-right">
                                                        <span className="font-heading text-sm font-semibold text-foreground tabular-nums">
                                                            {formatQty(
                                                                row.onHand,
                                                            )}{' '}
                                                            <span className="font-normal text-muted-foreground">
                                                                {row.baseUnit}
                                                            </span>
                                                        </span>
                                                        <span className="text-xs text-muted-foreground tabular-nums">
                                                            {currencyCode}{' '}
                                                            {stockValue.toFixed(
                                                                2,
                                                            )}
                                                            {row.threshold !==
                                                            null
                                                                ? ` · Alert <= ${formatRatio(row.threshold)}`
                                                                : ''}
                                                        </span>
                                                    </div>

                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        className="max-sm:min-h-11"
                                                        aria-label={`Edit ${row.name}`}
                                                        onClick={() =>
                                                            setEditing(row)
                                                        }
                                                    >
                                                        Edit
                                                    </Button>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                        </section>
                    )}

                    {section === 'recipes' && (
                        <div className="flex flex-col gap-4">
                            <div className="@container rounded-xl border bg-card px-4 py-3">
                                <div className="flex flex-col gap-3 @xl:flex-row @xl:items-center @xl:justify-between">
                                    <InputGroup className="w-full @xl:max-w-xs">
                                        <InputGroupAddon>
                                            <Search aria-hidden />
                                        </InputGroupAddon>
                                        <InputGroupInput
                                            ref={searchInputRef}
                                            type="search"
                                            aria-label="Search menu items"
                                            placeholder="Search menu items"
                                            value={recipeSearch}
                                            onChange={(event) =>
                                                setRecipeSearch(
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        {recipeSearch !== '' ? (
                                            <InputGroupAddon align="inline-end">
                                                <InputGroupButton
                                                    size="icon-xs"
                                                    aria-label="Clear menu item search"
                                                    onClick={() =>
                                                        setRecipeSearch('')
                                                    }
                                                >
                                                    <X />
                                                </InputGroupButton>
                                            </InputGroupAddon>
                                        ) : (
                                            <InputGroupAddon
                                                align="inline-end"
                                                className="hidden sm:flex"
                                            >
                                                <Kbd>/</Kbd>
                                            </InputGroupAddon>
                                        )}
                                    </InputGroup>

                                    <div
                                        role="group"
                                        aria-label="Filter recipes"
                                        className="flex flex-wrap items-center gap-1"
                                    >
                                        {(
                                            [
                                                {
                                                    value: 'all',
                                                    label: 'All',
                                                    count: variants.length,
                                                },
                                                {
                                                    value: 'linked',
                                                    label: 'With recipe',
                                                    count: stats.linkedRecipeCount,
                                                },
                                                {
                                                    value: 'unlinked',
                                                    label: 'Needs recipe',
                                                    count:
                                                        variants.length -
                                                        stats.linkedRecipeCount,
                                                },
                                            ] as const
                                        ).map((item) => (
                                            <Button
                                                key={item.value}
                                                type="button"
                                                size="xs"
                                                variant={
                                                    recipeFilter === item.value
                                                        ? 'secondary'
                                                        : 'ghost'
                                                }
                                                aria-pressed={
                                                    recipeFilter === item.value
                                                }
                                                className="gap-1.5 tabular-nums"
                                                onClick={() =>
                                                    setRecipeFilter(item.value)
                                                }
                                            >
                                                <span>{item.label}</span>
                                                <span className="text-muted-foreground">
                                                    {item.count}
                                                </span>
                                            </Button>
                                        ))}
                                    </div>
                                </div>
                            </div>

                            {variants.length === 0 ? (
                                <div className="rounded-xl border bg-card p-8">
                                    <EmptyState
                                        icon={BookOpen}
                                        title="No menu items yet"
                                        description="Create menu items under Menu first, then return here to link ingredients per portion."
                                    />
                                </div>
                            ) : filteredVariants.length === 0 ? (
                                <div className="rounded-xl border bg-card p-8">
                                    <EmptyState
                                        icon={Search}
                                        title="No matching menu items"
                                        description="No item matches your current search or recipe filter."
                                        action={
                                            <Button
                                                type="button"
                                                variant="outline"
                                                onClick={() => {
                                                    setRecipeSearch('');
                                                    setRecipeFilter('all');
                                                }}
                                            >
                                                Clear filters
                                            </Button>
                                        }
                                    />
                                </div>
                            ) : (
                                <div className="flex flex-col gap-3">
                                    {filteredVariants.map((variant) => (
                                        <PosRecipeRow
                                            key={variant.id}
                                            variant={variant}
                                            ingredients={ingredients}
                                            currencyCode={currencyCode}
                                        />
                                    ))}
                                </div>
                            )}
                        </div>
                    )}

                    {section === 'movements' && outlet !== null && (
                        <PosStockMovementWorkbench
                            outletId={outlet.id}
                            outletName={outlet.name}
                            outlets={outlets}
                            ingredients={ingredientOptions}
                            ingredientList={ingredients}
                            currencyCode={currencyCode}
                            mode={movementMode}
                            onModeChange={setMovementMode}
                        />
                    )}

                    {section === 'stocktake' && outlet !== null && (
                        <PosStocktakePanel
                            outletId={outlet.id}
                            outletName={outlet.name}
                            ingredients={ingredients}
                            stocktake={stocktake}
                        />
                    )}
                </div>

                <aside
                    aria-label="Stockroom summary"
                    className="flex flex-col gap-4 lg:sticky lg:top-4"
                >
                    <div className="flex flex-col gap-4 rounded-xl border bg-card p-5">
                        <div className="flex items-start justify-between gap-2">
                            <div className="flex flex-col gap-0.5">
                                <h2 className="font-heading text-base font-semibold text-foreground">
                                    Stockroom pulse
                                </h2>
                                <p className="text-xs text-muted-foreground">
                                    {outlet !== null
                                        ? outlet.name
                                        : 'All ingredients'}
                                </p>
                            </div>
                            <Badge variant="outline" className="tabular-nums">
                                {ingredients.length}{' '}
                                {ingredients.length === 1 ? 'item' : 'items'}
                            </Badge>
                        </div>

                        <div className="flex items-center gap-4">
                            <StockHealthRing
                                healthyCount={stats.healthyCount}
                                totalActive={stats.activeCount}
                            />
                            <div className="flex min-w-0 flex-col gap-1">
                                <span className="text-xs text-muted-foreground">
                                    Estimated stock value
                                </span>
                                <span className="font-heading text-xl font-bold tracking-tight text-foreground tabular-nums">
                                    {currencyCode}{' '}
                                    {formatQty(stats.totalStockValue)}
                                </span>
                                <span className="text-xs text-muted-foreground tabular-nums">
                                    {stats.healthyCount} of {stats.activeCount}{' '}
                                    active healthy
                                </span>
                            </div>
                        </div>

                        <dl className="divide-y rounded-lg border bg-muted/20 text-sm tabular-nums">
                            <div className="flex items-center justify-between px-3 py-2">
                                <dt className="text-muted-foreground">
                                    Active ingredients
                                </dt>
                                <dd className="font-heading font-semibold text-foreground">
                                    {stats.activeCount}
                                </dd>
                            </div>
                            <div className="flex items-center justify-between px-3 py-2">
                                <dt className="text-muted-foreground">
                                    Low stock alerts
                                </dt>
                                <dd
                                    className={cn(
                                        'font-heading font-semibold',
                                        stats.lowStockCount > 0
                                            ? 'text-warning-text'
                                            : 'text-foreground',
                                    )}
                                >
                                    {stats.lowStockCount}
                                </dd>
                            </div>
                            <div className="flex items-center justify-between px-3 py-2">
                                <dt className="text-muted-foreground">
                                    Recipes linked
                                </dt>
                                <dd className="font-heading font-semibold text-foreground">
                                    {stats.linkedRecipeCount} /{' '}
                                    {variants.length}
                                </dd>
                            </div>
                        </dl>

                        <div className="flex flex-col gap-1.5">
                            <div className="flex items-center justify-between text-xs text-muted-foreground tabular-nums">
                                <span>Recipe costing coverage</span>
                                <span>{stats.recipeCoveragePct}%</span>
                            </div>
                            <Progress
                                value={stats.recipeCoveragePct}
                                aria-label="Recipe costing coverage"
                            />
                        </div>
                    </div>

                    {outlet !== null && stocktake !== null && (
                        <div className="flex flex-col gap-3 rounded-xl border border-warning/40 bg-card p-4">
                            <div className="flex items-center justify-between gap-2">
                                <span className="inline-flex items-center gap-1.5 text-sm font-semibold text-foreground">
                                    <ClipboardCheck
                                        aria-hidden="true"
                                        className="size-4 text-warning-text"
                                    />
                                    Open closing count
                                </span>
                                <Badge
                                    variant="secondary"
                                    className="bg-warning/10 text-warning-text tabular-nums"
                                >
                                    {countedPct}%
                                </Badge>
                            </div>
                            <p className="text-xs text-muted-foreground tabular-nums">
                                {stocktake.lines.length} of {ingredients.length}{' '}
                                ingredients recorded for {outlet.name}.
                            </p>
                            {section !== 'stocktake' && (
                                <div className="flex justify-end">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="xs"
                                        onClick={() => setSection('stocktake')}
                                    >
                                        Continue count
                                    </Button>
                                </div>
                            )}
                        </div>
                    )}

                    {outlet !== null && (
                        <div className="flex flex-col gap-2.5 rounded-xl border bg-card p-4">
                            <h3 className="text-xs font-semibold text-foreground">
                                Quick stock actions
                            </h3>
                            <div className="flex flex-col gap-1.5">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="justify-start max-sm:min-h-11"
                                    onClick={() => {
                                        setMovementMode('receive');
                                        setSection('movements');
                                    }}
                                >
                                    <PackagePlus data-icon="inline-start" />
                                    Receive supplier delivery
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="justify-start max-sm:min-h-11"
                                    onClick={() => {
                                        setMovementMode('waste');
                                        setSection('movements');
                                    }}
                                >
                                    <TriangleAlert data-icon="inline-start" />
                                    Record kitchen waste
                                </Button>
                            </div>
                            <p className="border-t pt-2.5 text-xs text-muted-foreground">
                                Tip: press <Kbd>/</Kbd> to search ingredients or
                                recipes.
                            </p>
                        </div>
                    )}
                </aside>
            </div>

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
