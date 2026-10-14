import { Link, router } from '@inertiajs/react';
import { EnumSelect } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import { StatusBadge } from '@/components/pms/status-badge';
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
 */
export function FolioRail({
    reservationId,
    items,
    activeKey,
    currency,
}: {
    reservationId: number;
    items: Item[];
    activeKey: string;
    currency: App.Data.Property.CurrencyData | null;
}) {
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
                    onValueChange={(value) =>
                        router.get(
                            folioUrl(reservationId, value),
                            {},
                            {
                                preserveScroll: true,
                                preserveState: true,
                                replace: true,
                            },
                        )
                    }
                />
            </div>
            <nav
                aria-label="Choose folio"
                className="hidden h-fit flex-col gap-1 lg:flex"
            >
                {items.map((item) => (
                    <Link
                        key={item.key}
                        href={folioUrl(reservationId, item.key)}
                        preserveScroll
                        preserveState
                        aria-current={
                            item.key === activeKey ? 'true' : undefined
                        }
                        className={cn(
                            'flex flex-col gap-1 rounded-lg border px-3 py-2 text-sm hover:bg-muted',
                            item.key === activeKey && 'border-primary bg-muted',
                        )}
                    >
                        <span className="font-medium">{item.name}</span>
                        {item.subtitle && (
                            <span className="text-muted-foreground">
                                {item.subtitle}
                            </span>
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
                                        Number(item.balance) > 0 &&
                                            'text-destructive',
                                    )}
                                >
                                    <MoneyText
                                        amount={item.balance}
                                        currency={currency}
                                    />
                                </span>
                            )}
                        </span>
                    </Link>
                ))}
            </nav>
        </>
    );
}
