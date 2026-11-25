import { Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { MoneyText } from '@/components/pms/money-text';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import type { PosCategory, PosCurrency, PosItem } from './pos-types';

/**
 * Category tabs, search and touch tiles over the outlet menu snapshot. Filtering is in the browser; nothing is requested.
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
            return categories
                .flatMap((category) => category.items)
                .filter(
                    (item) =>
                        item.name.toLowerCase().includes(term) ||
                        item.variants.some(
                            (variant) =>
                                (variant.sku ?? '').toLowerCase() === term ||
                                (variant.barcode ?? '') === term,
                        ),
                );
        }

        return (
            categories.find((category) => category.id === categoryId)?.items ??
            []
        );
    }, [categories, categoryId, search]);

    return (
        <div className="flex min-h-0 flex-1 flex-col gap-3">
            <div className="relative">
                <Search
                    className="pointer-events-none absolute top-2.5 left-3 size-4 text-muted-foreground"
                    aria-hidden
                />
                <Input
                    className="pl-9"
                    placeholder="Search or scan a barcode"
                    aria-label="Search the menu"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
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
                        className="h-11 shrink-0"
                        onClick={() => {
                            setSearch('');
                            setCategoryId(category.id);
                        }}
                    >
                        {category.name}
                    </Button>
                ))}
            </div>
            <div className="grid min-h-0 flex-1 grid-cols-2 content-start gap-2 overflow-y-auto sm:grid-cols-3 xl:grid-cols-4">
                {items.map((item) => {
                    const soldOut = item.variants.every(
                        (variant) => variant.soldOut,
                    );
                    const price = item.variants[0]?.price ?? '0.00';

                    return (
                        <button
                            key={item.id}
                            type="button"
                            disabled={disabled || soldOut}
                            onClick={() => onPick(item)}
                            className={cn(
                                'flex min-h-20 flex-col items-start justify-between gap-1 rounded-lg border bg-card p-3 text-left transition-colors',
                                'hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50',
                            )}
                        >
                            <span className="text-sm leading-tight font-medium">
                                {item.name}
                            </span>
                            <span className="text-sm text-muted-foreground">
                                {soldOut ? (
                                    'Sold out'
                                ) : (
                                    <>
                                        {item.variants.length > 1 && 'from '}
                                        <MoneyText
                                            amount={price}
                                            currency={currency}
                                        />
                                    </>
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
