import { Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { MoneyText } from '@/components/pms/money-text';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { categorySwatchClass } from './pos-types';
import type { PosCategory, PosCurrency, PosItem } from './pos-types';

/**
 * Category tabs, search (with Enter-to-add barcode/SKU scanning) and touch tiles over the outlet menu snapshot.
 * Filtering is in the browser; nothing is requested.
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

    const items = useMemo(() => {
        const term = search.trim().toLowerCase();

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
    }, [categories, categoryId, search]);

    const handleSearchKeyDown = (
        event: React.KeyboardEvent<HTMLInputElement>,
    ) => {
        if (event.key !== 'Enter' || disabled) {
            return;
        }

        const term = search.trim().toLowerCase();
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
                    className="pointer-events-none absolute top-2.5 left-3 size-4 text-muted-foreground"
                    aria-hidden
                />
                <Input
                    className="pl-9"
                    placeholder="Search or scan barcode (Enter to add)"
                    aria-label="Search the menu"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    onKeyDown={handleSearchKeyDown}
                />
            </div>
            <div
                className="flex gap-2 overflow-x-auto pb-1"
                role="tablist"
                aria-label="Categories"
            >
                {categories.map((category) => (
                    <Button
                        key={category.id}
                        type="button"
                        role="tab"
                        aria-selected={
                            search === '' && category.id === categoryId
                        }
                        variant={
                            search === '' && category.id === categoryId
                                ? 'default'
                                : 'outline'
                        }
                        className="min-h-11 shrink-0 gap-2"
                        onClick={() => {
                            setSearch('');
                            setCategoryId(category.id);
                        }}
                    >
                        <span
                            aria-hidden
                            className={cn(
                                'size-2.5 shrink-0 rounded-full',
                                categorySwatchClass(category.colorToken),
                            )}
                        />
                        {category.name}
                    </Button>
                ))}
            </div>
            <div className="grid min-h-0 flex-1 grid-cols-2 content-start gap-2 overflow-y-auto sm:grid-cols-3 xl:grid-cols-4">
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

                    return (
                        <button
                            key={item.id}
                            type="button"
                            disabled={disabled}
                            onClick={() => onPick(item)}
                            className={cn(
                                'relative flex min-h-20 flex-col items-start justify-between gap-1.5 overflow-hidden rounded-lg border bg-card p-3 pl-4 text-left transition-colors',
                                'hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50',
                                soldOut && 'border-warning-border/70 opacity-80',
                            )}
                        >
                            <span
                                aria-hidden
                                className={cn(
                                    'absolute inset-y-0 left-0 w-1.5',
                                    categorySwatchClass(colorToken),
                                )}
                            />
                            <div className="flex w-full items-start justify-between gap-1">
                                <span className="text-sm leading-tight font-medium">
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
                            <span className="flex w-full items-center justify-between gap-1 text-sm text-muted-foreground">
                                <span>
                                    {item.variants.length > 1 && 'from '}
                                    <MoneyText
                                        amount={price}
                                        currency={currency}
                                    />
                                </span>
                                {soldOut && (
                                    <span className="text-xs font-medium text-warning-text">
                                        Sold out (override)
                                    </span>
                                )}
                            </span>
                        </button>
                    );
                })}
                {items.length === 0 && (
                    <p className="col-span-full text-sm text-muted-foreground">
                        Nothing matches.
                    </p>
                )}
            </div>
        </div>
    );
}
