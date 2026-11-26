import { Search, X } from 'lucide-react';
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
 * Category filters, search (with Enter-to-add barcode/SKU scanning), and touch tiles over the outlet menu snapshot.
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

    const items = useMemo(() => {
        const term = trimmedSearch.toLowerCase();

        if (term !== '') {
            return categories.flatMap((category) =>
                category.items
                    .filter(
                        (item) =>
                            item.name.toLowerCase().includes(term) ||
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
                        colorToken: category.colorToken,
                    })),
            );
        }

        const activeCategory = categories.find(
            (category) => category.id === categoryId,
        );

        return (activeCategory?.items ?? []).map((item) => ({
            item,
            colorToken: activeCategory?.colorToken ?? 'category-1',
        }));
    }, [categories, categoryId, trimmedSearch]);

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
            <div className="relative">
                <Search
                    className="pointer-events-none absolute top-3.5 left-3.5 size-4 text-muted-foreground"
                    aria-hidden="true"
                />
                <Input
                    className="h-11 pr-11 pl-10"
                    placeholder="Search menu or scan barcode (Enter to add)"
                    aria-label="Search menu or scan barcode"
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
            </div>

            {categories.length > 0 && (
                <div
                    className="flex snap-x gap-2 overflow-x-auto pb-1"
                    role="group"
                    aria-label="Menu categories"
                >
                    {categories.map((category) => {
                        const isSelected =
                            trimmedSearch === '' && category.id === categoryId;

                        return (
                            <Button
                                key={category.id}
                                type="button"
                                aria-pressed={isSelected}
                                variant={isSelected ? 'default' : 'outline'}
                                className="min-h-11 shrink-0 snap-start gap-2 px-3.5"
                                onClick={() => {
                                    setSearch('');
                                    setCategoryId(category.id);
                                }}
                            >
                                <span
                                    aria-hidden="true"
                                    className={cn(
                                        'size-2.5 shrink-0 rounded-full',
                                        isSelected
                                            ? 'bg-primary-foreground'
                                            : categorySwatchClass(
                                                  category.colorToken,
                                              ),
                                    )}
                                />
                                <span>{category.name}</span>
                                <span
                                    className={cn(
                                        'text-xs tabular-nums',
                                        isSelected
                                            ? 'text-primary-foreground/80'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {category.items.length}
                                </span>
                            </Button>
                        );
                    })}
                </div>
            )}

            <div className="grid min-h-0 flex-1 grid-cols-2 content-start gap-2.5 overflow-y-auto pr-0.5 sm:grid-cols-3 xl:grid-cols-4">
                {items.map(({ item, colorToken }) => {
                    const soldOut = item.variants.every(
                        (variant) => variant.soldOut,
                    );
                    const price = item.variants[0]?.price ?? '0.00';
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
                            ? `${item.variants.length} options`
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
                                'relative flex min-h-24 flex-col items-start justify-between gap-2 overflow-hidden rounded-xl border bg-card p-3.5 pl-4 text-left transition-colors',
                                'hover:border-foreground/25 hover:bg-muted/70 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none active:translate-y-px disabled:opacity-50',
                                soldOut && 'border-warning/60 bg-warning/5',
                            )}
                        >
                            <span
                                aria-hidden="true"
                                className={cn(
                                    'absolute inset-y-0 left-0 w-1.5',
                                    categorySwatchClass(colorToken),
                                )}
                            />
                            <div className="flex w-full items-start justify-between gap-1.5">
                                <span className="line-clamp-2 text-sm leading-snug font-medium text-foreground">
                                    {item.name}
                                </span>
                                {lowestStock !== null && (
                                    <Badge
                                        variant={
                                            lowestStock <= 0
                                                ? 'destructive'
                                                : 'secondary'
                                        }
                                        className="shrink-0 tabular-nums"
                                    >
                                        {lowestStock <= 0
                                            ? '0 left'
                                            : `${lowestStock} left`}
                                    </Badge>
                                )}
                            </div>

                            <div className="flex w-full flex-col gap-0.5">
                                {metaLabel !== null && (
                                    <span className="text-xs text-muted-foreground">
                                        {metaLabel}
                                    </span>
                                )}
                                <div className="flex w-full items-baseline justify-between gap-1.5">
                                    <span className="text-sm font-semibold text-foreground tabular-nums">
                                        {item.variants.length > 1 && (
                                            <span className="font-normal text-muted-foreground">
                                                from{' '}
                                            </span>
                                        )}
                                        <MoneyText
                                            amount={price}
                                            currency={currency}
                                        />
                                    </span>
                                    {soldOut && (
                                        <span className="text-xs font-medium text-warning-text">
                                            Sold out
                                        </span>
                                    )}
                                </div>
                            </div>
                        </button>
                    );
                })}
                {items.length === 0 && (
                    <div className="col-span-full py-6">
                        <EmptyState
                            icon={Search}
                            title={
                                trimmedSearch !== ''
                                    ? `No items match "${trimmedSearch}"`
                                    : 'No items in this category'
                            }
                            description={
                                trimmedSearch !== ''
                                    ? 'Check the spelling or scan another barcode.'
                                    : 'Add items to this category under POS, Menu.'
                            }
                            action={
                                trimmedSearch !== '' ? (
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
