import { Head, Link } from '@inertiajs/react';
import { BedDouble, CalendarDays, FileText } from 'lucide-react';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { PortalArrivalRow } from '@/components/pms/portal/portal-arrival-row';
import { PortalRecentBookings } from '@/components/pms/portal/portal-recent-bookings';
import { PortalStatTile } from '@/components/pms/portal/portal-stat-tile';
import { StatusBadge } from '@/components/pms/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { buttonVariants } from '@/components/ui/button';
import {
    Card,
    CardAction,
    CardContent,
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
import { show, statements } from '@/routes/portal';
import { create, index as bookings } from '@/routes/portal/bookings';
import { pdf as invoicePdf } from '@/routes/portal/statements/invoices';

type Currency = App.Data.Property.CurrencyData | null;

const overdueBadge: App.Data.Shared.BadgeData = {
    value: 'overdue',
    label: 'Overdue',
    tone: 'danger',
};

function plural(count: number, noun: string): string {
    return `${count} ${noun}${count === 1 ? '' : 's'}`;
}

export default function PortalShow({
    propertyName,
    propertyCode,
    companyName,
    companyStatus,
    companySuspended,
    cancelCutoffDays,
    stats,
    arrivals,
    openInvoices,
    recentBookings,
    currency,
}: {
    propertyName: string;
    propertyCode: string;
    companyName: string;
    companyStatus: App.Data.Shared.BadgeData;
    companySuspended: boolean;
    cancelCutoffDays: number | null;
    stats: App.Data.Portal.PortalDashboardStatsData;
    arrivals: App.Data.Portal.PortalArrivalData[];
    openInvoices: App.Data.CityLedger.ArInvoiceRowData[];
    recentBookings: App.Data.Portal.PortalRecentBookingData[];
    currency: Currency;
}) {
    const book = companySuspended ? undefined : (
        <Link
            href={create({ property: propertyCode })}
            className={buttonVariants()}
        >
            Book a stay
        </Link>
    );
    const cancellation =
        cancelCutoffDays === null
            ? 'Cancellations by request only'
            : `Free cancellation until ${plural(cancelCutoffDays, 'day')} before arrival`;

    return (
        <>
            <Head title={propertyName} />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title={propertyName}
                    description={`${companyName} · ${cancellation}`}
                    badge={<StatusBadge status={companyStatus} />}
                    actions={book}
                />

                {companySuspended && (
                    <Alert>
                        <AlertDescription>
                            Your company can&apos;t book at this property right
                            now. Contact the property.
                        </AlertDescription>
                    </Alert>
                )}

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <PortalStatTile
                        title="Open balance"
                        figure={
                            <MoneyText
                                amount={stats.openBalance}
                                currency={currency}
                            />
                        }
                        note={plural(stats.openInvoices, 'open invoice')}
                    />
                    <PortalStatTile
                        title="Overdue"
                        figure={
                            <MoneyText
                                amount={stats.overdue}
                                currency={currency}
                            />
                        }
                        note={plural(stats.overdueInvoices, 'invoice')}
                    />
                    <PortalStatTile
                        title="Arrivals, 7 days"
                        figure={stats.arrivals}
                        note={`${stats.inHouse} in-house now`}
                    />
                    <PortalStatTile
                        title="Credit available"
                        figure={
                            stats.creditAvailable === null ? (
                                'No limit'
                            ) : (
                                <MoneyText
                                    amount={stats.creditAvailable}
                                    currency={currency}
                                />
                            )
                        }
                        note={
                            stats.creditLimit === null ? (
                                'No credit limit'
                            ) : (
                                <>
                                    of{' '}
                                    <MoneyText
                                        amount={stats.creditLimit}
                                        currency={currency}
                                        plain
                                    />{' '}
                                    limit
                                </>
                            )
                        }
                    />
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card className="min-h-64">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <CalendarDays className="size-4" />
                                Upcoming arrivals
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {arrivals.length === 0 ? (
                                <EmptyState
                                    icon={CalendarDays}
                                    title="No arrivals in the next 7 days."
                                    action={book}
                                />
                            ) : (
                                <ul className="flex flex-col gap-3">
                                    {arrivals.map((arrival) => (
                                        <PortalArrivalRow
                                            key={arrival.reservationId}
                                            arrival={arrival}
                                        />
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>

                    <Card className="min-h-64">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <FileText className="size-4" />
                                Open invoices
                            </CardTitle>
                            <CardAction>
                                <Link
                                    href={statements({
                                        property: propertyCode,
                                    })}
                                    className={buttonVariants({
                                        variant: 'outline',
                                        size: 'sm',
                                    })}
                                >
                                    View statements
                                </Link>
                            </CardAction>
                        </CardHeader>
                        <CardContent>
                            {openInvoices.length === 0 ? (
                                <EmptyState
                                    icon={FileText}
                                    title="No open invoices."
                                />
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Number</TableHead>
                                            <TableHead>Date</TableHead>
                                            <TableHead>Due</TableHead>
                                            <TableHead className="text-right">
                                                Amount
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Balance
                                            </TableHead>
                                            <TableHead>PDF</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {openInvoices.map((invoice) => (
                                            <TableRow key={invoice.id}>
                                                <TableCell className="font-medium">
                                                    <span className="flex items-center gap-2">
                                                        {invoice.number}
                                                        {invoice.overdue && (
                                                            <StatusBadge
                                                                status={
                                                                    overdueBadge
                                                                }
                                                            />
                                                        )}
                                                    </span>
                                                </TableCell>
                                                <TableCell>
                                                    <DateText
                                                        date={invoice.issuedOn}
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <DateText
                                                        date={invoice.dueOn}
                                                    />
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <MoneyText
                                                        amount={invoice.amount}
                                                        currency={currency}
                                                    />
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <MoneyText
                                                        amount={
                                                            invoice.balance ??
                                                            '0.00'
                                                        }
                                                        currency={currency}
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <a
                                                        href={invoicePdf.url({
                                                            property:
                                                                propertyCode,
                                                            invoice: invoice.id,
                                                        })}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className={buttonVariants(
                                                            {
                                                                variant:
                                                                    'outline',
                                                                size: 'sm',
                                                            },
                                                        )}
                                                    >
                                                        PDF
                                                    </a>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <BedDouble className="size-4" />
                            Recent bookings
                        </CardTitle>
                        <CardAction>
                            <Link
                                href={bookings({ property: propertyCode })}
                                className={buttonVariants({
                                    variant: 'outline',
                                    size: 'sm',
                                })}
                            >
                                View all
                            </Link>
                        </CardAction>
                    </CardHeader>
                    <CardContent>
                        <PortalRecentBookings
                            bookings={recentBookings}
                            emptyAction={book}
                        />
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

PortalShow.layout = (props: { propertyCode: string }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: show({ property: props.propertyCode }) },
    ],
});
