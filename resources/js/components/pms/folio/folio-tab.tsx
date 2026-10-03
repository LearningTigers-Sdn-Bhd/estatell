import { CreditCard } from 'lucide-react';
import { EmptyState } from '@/components/pms/empty-state';
import { FolioRail } from '@/components/pms/folio/folio-rail';
import { FolioRowsTable } from '@/components/pms/folio/folio-rows-table';
import { MoneyText } from '@/components/pms/money-text';
import { StatusBadge } from '@/components/pms/status-badge';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { cn } from '@/lib/utils';

function Figure({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-1 rounded-lg bg-muted px-3 py-2">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="font-medium">{children}</dd>
        </div>
    );
}

/**
 * The Folio tab: the folio rail (hidden for one entry) and the open folio with its Balance, Projected total and
 * rows. Read only. Everything is worked out on the server.
 */
export function FolioTab({
    reservationId,
    folios,
    folio,
    currency,
}: {
    reservationId: number;
    folios: App.Data.Folio.FolioRailItemData[];
    folio: App.Data.Folio.FolioData | null;
    currency: App.Enums.Currency | null;
}) {
    if (folio === null) {
        return (
            <EmptyState
                icon={CreditCard}
                title="No folio yet"
                description="Charges appear here once a unit has nights or an add-on."
            />
        );
    }

    const rail = folios.length > 1;

    return (
        <div className={cn('grid gap-4', rail && 'lg:grid-cols-[14rem_1fr]')}>
            {rail && (
                <FolioRail
                    reservationId={reservationId}
                    items={folios}
                    activeKey={folio.key}
                    currency={currency}
                />
            )}
            <Card>
                <CardHeader className="flex flex-row items-center justify-between gap-3">
                    <div className="flex flex-col gap-1">
                        <h2 className="font-heading text-base font-medium">
                            {folio.name}
                        </h2>
                        {folio.invoiceNumber && (
                            <p className="text-sm text-muted-foreground">
                                Invoice {folio.invoiceNumber}
                            </p>
                        )}
                    </div>
                    {folio.status && <StatusBadge status={folio.status} />}
                </CardHeader>
                <CardContent className="flex flex-col gap-4">
                    <dl className="grid gap-2 sm:grid-cols-3">
                        <Figure label="Balance">
                            {folio.balance === null ? (
                                <span className="text-muted-foreground">—</span>
                            ) : (
                                <span
                                    className={cn(
                                        Number(folio.balance) > 0 &&
                                            'text-destructive',
                                    )}
                                >
                                    <MoneyText
                                        amount={folio.balance}
                                        currency={currency}
                                    />
                                </span>
                            )}
                        </Figure>
                        <Figure label="Projected total">
                            <MoneyText
                                amount={folio.projectedTotal}
                                currency={currency}
                            />
                        </Figure>
                        <Figure label="Posted lines">
                            <span className="tabular-nums">
                                {folio.lineCount}
                            </span>
                        </Figure>
                    </dl>
                    {folio.rows.length === 0 ? (
                        <EmptyState
                            icon={CreditCard}
                            title="Nothing on this folio"
                            description="Posted and scheduled charges appear here."
                        />
                    ) : (
                        <FolioRowsTable rows={folio.rows} currency={currency} />
                    )}
                </CardContent>
            </Card>
        </div>
    );
}
