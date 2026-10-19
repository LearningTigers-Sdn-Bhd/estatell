import { Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { EnumSelect } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { folio as folioTab } from '@/routes/reservations';

type Item = App.Data.Folio.FolioRailItemData;

function folioUrl(reservationId: number, key: string): string {
    return folioTab.url(
        { reservation: reservationId },
        { query: { folio: key } },
    );
}

/**
 * The folios of a reservation: a rail from `lg` and a select below it. The balance is in the danger tone when above 0.
 * By default an entry opens the folio tab on that folio. With `onSelect` it calls back instead (the manual folio page
 * switches folios itself), `drafts` adds "N new" to each folio with draft rows, and `onAdd` shows **+ Add folio**.
 */
export function FolioRail({
    reservationId,
    items,
    activeKey,
    currency,
    onSelect,
    drafts,
    onAdd,
}: {
    reservationId: number;
    items: Item[];
    activeKey: string;
    currency: App.Data.Property.CurrencyData | null;
    onSelect?: (key: string) => void;
    /** Draft rows by folio key. */
    drafts?: Record<string, number>;
    /** Adds a button under the folios (the Manual Folio Editor adds a Third party folio). */
    onAdd?: () => void;
}) {
    const select = (key: string) =>
        onSelect
            ? onSelect(key)
            : router.get(
                  folioUrl(reservationId, key),
                  {},
                  {
                      preserveScroll: true,
                      preserveState: true,
                      replace: true,
                  },
              );

    return (
        <>
            <div className="lg:hidden">
                <EnumSelect
                    aria-label="Choose folio"
                    options={items.map((item) => ({
                        value: item.key,
                        label: [item.name, item.subtitle, item.status?.label]
                            .filter(Boolean)
                            .join(' · '),
                    }))}
                    value={activeKey}
                    onValueChange={select}
                />
                {onAdd && (
                    <Button
                        type="button"
                        variant="outline"
                        className="mt-2 w-full"
                        onClick={onAdd}
                    >
                        <Plus data-icon="inline-start" />
                        Add folio
                    </Button>
                )}
            </div>
            <nav
                aria-label="Choose folio"
                className="hidden h-fit flex-col gap-1 lg:flex"
            >
                {items.map((item) => {
                    const className = cn(
                        'flex flex-col gap-1 rounded-lg border px-3 py-2 text-left text-sm hover:bg-muted',
                        item.key === activeKey && 'border-primary bg-muted',
                    );
                    const current =
                        item.key === activeKey ? ('true' as const) : undefined;
                    const content = (
                        <RailEntry
                            item={item}
                            currency={currency}
                            drafts={drafts?.[item.key] ?? 0}
                        />
                    );

                    return onSelect ? (
                        <button
                            key={item.key}
                            type="button"
                            aria-current={current}
                            className={className}
                            onClick={() => onSelect(item.key)}
                        >
                            {content}
                        </button>
                    ) : (
                        <Link
                            key={item.key}
                            href={folioUrl(reservationId, item.key)}
                            preserveScroll
                            preserveState
                            aria-current={current}
                            className={className}
                        >
                            {content}
                        </Link>
                    );
                })}
                {onAdd && (
                    <Button type="button" variant="outline" onClick={onAdd}>
                        <Plus data-icon="inline-start" />
                        Add folio
                    </Button>
                )}
            </nav>
        </>
    );
}

function RailEntry({
    item,
    currency,
    drafts,
}: {
    item: Item;
    currency: App.Data.Property.CurrencyData | null;
    drafts: number;
}) {
    return (
        <>
            <span className="font-medium">{item.name}</span>
            {item.subtitle && (
                <span className="text-muted-foreground">{item.subtitle}</span>
            )}
            <span className="flex items-center justify-between gap-2">
                {item.status ? (
                    <StatusBadge status={item.status} />
                ) : (
                    <span className="text-xs text-muted-foreground">
                        Scheduled only
                    </span>
                )}
                {item.balance !== null && (
                    <span
                        className={cn(
                            Number(item.balance) > 0 && 'text-destructive',
                        )}
                    >
                        <MoneyText amount={item.balance} currency={currency} />
                    </span>
                )}
            </span>
            {drafts > 0 && (
                <span className="text-xs font-medium text-primary">
                    {drafts} new
                </span>
            )}
        </>
    );
}
