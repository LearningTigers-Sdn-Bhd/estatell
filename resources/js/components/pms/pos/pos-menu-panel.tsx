import { Plus, Search, UtensilsCrossed, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { MoneyText } from '@/components/pms/money-text';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { categorySwatchClass } from './pos-types';
import type { PosCategory, PosCurrency, PosItem } from './pos-types';

/**
 * Category filters, barcode/SKU search, and touch menu cards over the outlet snapshot.
 * Filtering runs in the browser with no server request.
 */
export function PosMenuPanel({
    categories,
    currency,
    disabled,
    onPick,
}: {
    categories: PosCategory[];
    currency: PosCurrency;
    disabled: boolean;
    onPick: (item: PosItem) => void;
}) {
    const [categoryId, setCategoryId] = useState<number | null>(
        categories[0]?.id ?? null,
    );
    const [search, setSearch] = useState('');

    const trimmedSearch = search.trim();
    const isSearching = trimmedSearch !== '';

    const totalItems = useMemo(
        () =>
            categories.reduce(
                (sum, category) => sum + category.items.length,
                0,
            ),
        [categories],
    );

    const items = useMemo(() => {
        const term = trimmedSearch.toLowerCase();

        if (term !== '') {
            return categories.flatMap((category) =>
                category.items
                    .filter(
                        (item) =>
                            item.name.toLowerCase().includes(term) ||
                            (item.description ?? '')
                                .toLowerCase()
                                .includes(term) ||
                            item.variants.some(
                                (variant) =>
                                    (variant.sku ?? '').toLowerCase() ===
                                        term ||
                                    (variant.barcode ?? '').toLowerCase() ===
                                        term,
                            ),
                    )
                    .map((item) => ({
                        item,
                        categoryName: category.name,
                        colorToken: category.colorToken,
                    })),
            );
        }

        if (categoryId === null) {
            return categories.flatMap((category) =>
                category.items.map((item) => ({
                    item,
                    categoryName: category.name,
                    colorToken: category.colorToken,
                })),
            );
        }

        const activeCategory = categories.find(
            (category) => category.id === categoryId,
        );

        return (activeCategory?.items ?? []).map((item) => ({
            item,
            categoryName: activeCategory?.name ?? '',
            colorToken: activeCategory?.colorToken ?? 'category-1',
        }));
    }, [categories, categoryId, trimmedSearch]);

    const showMediaSlot = useMemo(
        () => items.some(({ item }) => Boolean(item.imageUrl)),
        [items],
    );

    const showCategoryContext =
        isSearching || (categories.length > 1 && categoryId === null);

    const handleSearchKeyDown = (
        event: React.KeyboardEvent<HTMLInputElement>,
    ) => {
        if (event.key !== 'Enter' || disabled) {
            return;
        }

        const term = trimmedSearch.toLowerCase();
        if (term === '') {
            return;
        }

        const exact = categories
            .flatMap((category) => category.items)
            .find((item) =>
                item.variants.some(
                    (variant) =>
                        (variant.barcode ?? '').toLowerCase() === term ||
                        (variant.sku ?? '').toLowerCase() === term,
                ),
            );

        if (exact !== undefined) {
            event.preventDefault();
            onPick(exact);
            setSearch('');
        }
    };

    return (
        <div className="flex min-h-0 flex-1 flex-col gap-3">
            <search aria-label="Search menu" className="relative">
                <Search
                    className="pointer-events-none absolute top-3.5 left-3.5 size-4 text-muted-foreground"
                    aria-hidden="true"
                />
                <Input
                    type="text"
                    role="searchbox"
                    className="h-11 bg-card pr-11 pl-10"
                    placeholder="Search menu or scan barcode (Enter to add)"
                    aria-label="Search menu or scan barcode"
                    autoCorrect="off"
                    autoCapitalize="none"
                    spellCheck={false}
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    onKeyDown={handleSearchKeyDown}
                />
                {search !== '' && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="absolute top-0 right-0 size-11"
                        aria-label="Clear search"
                        onClick={() => setSearch('')}
                    >
                        <X aria-hidden="true" />
                    </Button>
                )}
            </search>

            {categories.length > 0 && (
                <div
                    className="flex snap-x gap-1.5 overflow-x-auto pb-0.5"
                    role="group"
                    aria-label="Menu categories"
                >
                    {categories.length > 1 && (
                        <button
                            type="button"
                            aria-pressed={!isSearching && categoryId === null}
                            onClick={() => {
                                setSearch('');
                                setCategoryId(null);
                            }}
                            className={cn(
                                'inline-flex min-h-11 shrink-0 snap-start items-center gap-2 rounded-lg px-3.5 text-sm font-medium transition-colors focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none motion-reduce:transition-none',
                                !isSearching && categoryId === null
                                    ? 'bg-foreground font-semibold text-background'
                                    : 'border bg-card text-foreground hover:bg-muted/70',
                            )}
                        >
                            <span>All</span>
                            <span
                                className={cn(
                                    'text-xs tabular-nums',
                                    !isSearching && categoryId === null
                                        ? 'text-background/75'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {totalItems}
                            </span>
                        </button>
                    )}
                    {categories.map((category) => {
                        const isSelected =
                            !isSearching && category.id === categoryId;

                        return (
                            <button
                                key={category.id}
                                type="button"
                                aria-pressed={isSelected}
                                onClick={() => {
                                    setSearch('');
                                    setCategoryId(category.id);
                                }}
                                className={cn(
                                    'inline-flex min-h-11 shrink-0 snap-start items-center gap-2 rounded-lg px-3.5 text-sm font-medium transition-colors focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none motion-reduce:transition-none',
                                    isSelected
                                        ? 'bg-foreground font-semibold text-background'
                                        : 'border bg-card text-foreground hover:bg-muted/70',
                                )}
                            >
                                <span
                                    aria-hidden="true"
                                    className={cn(
                                        'size-2 shrink-0 rounded-full',
                                        categorySwatchClass(
                                            category.colorToken,
                                        ),
                                    )}
                                />
                                <span>{category.name}</span>
                                <span
                                    className={cn(
                                        'text-xs tabular-nums',
                                        isSelected
                                            ? 'text-background/75'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {category.items.length}
                                </span>
                            </button>
                        );
                    })}
                </div>
            )}

            <div className="grid min-h-0 flex-1 grid-cols-2 content-start gap-3 overflow-y-auto pr-0.5 sm:grid-cols-3 xl:grid-cols-4">
                {items.map(({ item, categoryName, colorToken }) => {
                    const soldOut = item.variants.every(
                        (variant) => variant.soldOut,
                    );
                    const firstVariant = item.variants[0];
                    const price = firstVariant?.price ?? '0.00';
                    const isVariablePrice =
                        item.variants.length === 1 &&
                        firstVariant?.pricingType === 'variable';
                    const countdowns = item.variants
                        .map((variant) => variant.stockCountdown)
                        .filter((count): count is number => count !== null);
                    const lowestStock =
                        countdowns.length > 0 ? Math.min(...countdowns) : null;
                    const metaLabel = item.isCombo
                        ? 'Combo'
                        : item.soldByWeight
                          ? `Per ${item.weightUnit ?? 'weight'}`
                          : item.variants.length > 1
                            ? `${item.variants.length} sizes`
                            : item.modifierGroups.length > 0
                              ? 'Customizable'
                              : null;

                    return (
                        <button
                            key={item.id}
                            type="button"
                            disabled={disabled}
                            onClick={() => onPick(item)}
                            className={cn(
                                'group flex flex-col overflow-hidden rounded-xl border bg-card text-left transition-all motion-reduce:transition-none',
                                'hover:border-foreground/25 hover:shadow-sm focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none disabled:opacity-50 motion-safe:active:translate-y-px',
                                !showMediaSlot && 'min-h-28',
                                soldOut && 'border-warning/60 bg-warning/5',
                            )}
                        >
                            {showMediaSlot && (
                                <div className="relative aspect-4/3 w-full overflow-hidden border-b bg-muted/50">
                                    {item.imageUrl ? (
                                        <img
                                            src={item.imageUrl}
                                            alt=""
                                            width={400}
                                            height={300}
                                            loading="lazy"
                                            className={cn(
                                                'size-full object-cover transition-transform duration-300 group-hover:scale-105 motion-reduce:transition-none motion-reduce:group-hover:scale-100',
                                                soldOut &&
                                                    'opacity-55 grayscale',
                                            )}
                                        />
                                    ) : (
                                        <div
                                            aria-hidden="true"
                                            className="flex size-full items-center justify-center bg-muted/60 text-muted-foreground"
                                        >
                                            <UtensilsCrossed className="size-7 opacity-40" />
                                        </div>
                                    )}

                                    {(metaLabel !== null ||
                                        lowestStock !== null ||
                                        soldOut) && (
                                        <div className="pointer-events-none absolute inset-x-2 top-2 flex items-start justify-between gap-1.5">
                                            {metaLabel !== null ? (
                                                <Badge
                                                    variant="outline"
                                                    className="border-border/80 bg-card/95 text-card-foreground shadow-xs"
                                                >
                                                    {metaLabel}
                                                </Badge>
                                            ) : (
                                                <span />
                                            )}
                                            {soldOut ? (
                                                <Badge
                                                    variant="destructive"
                                                    className="shadow-xs"
                                                >
                                                    Sold out
                                                </Badge>
                                            ) : (
                                                lowestStock !== null && (
                                                    <Badge
                                                        variant={
                                                            lowestStock <= 0
                                                                ? 'destructive'
                                                                : 'secondary'
                                                        }
                                                        className="tabular-nums shadow-xs"
                                                    >
                                                        {lowestStock <= 0
                                                            ? '0 left'
                                                            : `${lowestStock} left`}
                                                    </Badge>
                                                )
                                            )}
                                        </div>
                                    )}
                                </div>
                            )}

                            <div className="flex flex-1 flex-col justify-between gap-3 p-3">
                                <div className="flex flex-col gap-1">
                                    {showCategoryContext && (
                                        <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                            <span
                                                aria-hidden="true"
                                                className={cn(
                                                    'size-2 shrink-0 rounded-full',
                                                    categorySwatchClass(
                                                        colorToken,
                                                    ),
                                                )}
                                            />
                                            <span className="truncate">
                                                {categoryName}
                                            </span>
                                        </div>
                                    )}

                                    <div className="flex items-start justify-between gap-2">
                                        <span className="line-clamp-2 font-heading text-sm leading-snug font-semibold text-foreground">
                                            {item.name}
                                        </span>
                                        {!showMediaSlot &&
                                            !showCategoryContext && (
                                                <span
                                                    aria-hidden="true"
                                                    className={cn(
                                                        'mt-1.5 size-2 shrink-0 rounded-full',
                                                        categorySwatchClass(
                                                            colorToken,
                                                        ),
                                                    )}
                                                />
                                            )}
                                    </div>

                                    {item.description && (
                                        <p className="line-clamp-1 text-xs text-muted-foreground">
                                            {item.description}
                                        </p>
                                    )}

                                    {!showMediaSlot &&
                                        (metaLabel !== null ||
                                            lowestStock !== null) && (
                                            <div className="mt-0.5 flex flex-wrap items-center gap-1.5">
                                                {metaLabel !== null && (
                                                    <span className="text-xs text-muted-foreground">
                                                        {metaLabel}
                                                    </span>
                                                )}
                                                {lowestStock !== null && (
                                                    <Badge
                                                        variant={
                                                            lowestStock <= 0
                                                                ? 'destructive'
                                                                : 'secondary'
                                                        }
                                                        className="tabular-nums"
                                                    >
                                                        {lowestStock <= 0
                                                            ? '0 left'
                                                            : `${lowestStock} left`}
                                                    </Badge>
                                                )}
                                            </div>
                                        )}
                                </div>

                                <div className="flex items-center justify-between gap-2 pt-0.5">
                                    <div className="flex min-w-0 flex-col">
                                        {isVariablePrice ? (
                                            <span className="text-xs font-medium text-muted-foreground">
                                                Price at register
                                            </span>
                                        ) : (
                                            <span className="font-heading text-sm font-semibold text-foreground tabular-nums">
                                                {item.variants.length > 1 && (
                                                    <span className="font-sans text-xs font-normal text-muted-foreground">
                                                        from{' '}
                                                    </span>
                                                )}
                                                <MoneyText
                                                    amount={price}
                                                    currency={currency}
                                                />
                                            </span>
                                        )}
                                    </div>

                                    {soldOut ? (
                                        !showMediaSlot && (
                                            <span className="text-xs font-medium text-warning-text">
                                                Sold out
                                            </span>
                                        )
                                    ) : (
                                        <span
                                            aria-hidden="true"
                                            className="flex size-8 shrink-0 items-center justify-center rounded-lg border bg-muted/50 text-foreground transition-colors group-hover:border-primary group-hover:bg-primary group-hover:text-primary-foreground motion-reduce:transition-none"
                                        >
                                            <Plus className="size-4" />
                                        </span>
                                    )}
                                </div>
                            </div>
                        </button>
                    );
                })}
                {items.length === 0 && (
                    <div className="col-span-full flex items-center justify-center rounded-xl border bg-card py-10">
                        <EmptyState
                            icon={Search}
                            title={
                                isSearching
                                    ? `No items match "${trimmedSearch}"`
                                    : 'No items in this category'
                            }
                            description={
                                isSearching
                                    ? 'Check the spelling or scan another barcode.'
                                    : 'Add items to this category in POS under Menu.'
                            }
                            action={
                                isSearching ? (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="min-h-11"
                                        onClick={() => setSearch('')}
                                    >
                                        Clear search
                                    </Button>
                                ) : undefined
                            }
                        />
                    </div>
                )}
            </div>
        </div>
    );
}
