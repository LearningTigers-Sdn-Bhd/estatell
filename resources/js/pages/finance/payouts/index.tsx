import { Head, router } from '@inertiajs/react';
import { FileDown, Rows3 } from 'lucide-react';
import { useMemo } from 'react';
import {
    commissionBookingAlign,
    commissionBookingColumns,
} from '@/components/pms/commissions/booking-columns';
import {
    PayoutTabs,
    payoutTabs,
} from '@/components/pms/commissions/payout-tabs';
import { withTabCrumb } from '@/components/pms/tab-links';
import { TotalCard } from '@/components/pms/commissions/total-card';
import { DataTable } from '@/components/pms/data-table';
import { DatePicker } from '@/components/pms/date-picker';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FilterBar } from '@/components/pms/filter-bar';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { buttonVariants } from '@/components/ui/button';
import { exportMethod, index } from '@/routes/finance/payouts';
import { show as reservation } from '@/routes/reservations';

type Row = App.Data.Commissions.CommissionBookingRowData;
type Filters = App.Data.Commissions.CommissionBookingFilters;
type Summary = App.Data.Commissions.PropertyPayoutSummaryData;
type Query = { period: string; date: string; service: string; search: string };

type Props = {
    rows: Illuminate.LengthAwarePaginator<number, Row>;
    footer: {
        value: string;
        commissions: Record<string, string>;
        outstanding: string;
    };
    summary: Summary;
    filters: Filters;
    periods: EnumOption[];
    services: EnumOption[];
    canOpenReservations: boolean;
};

/** The URL query of the filters, leaving out the defaults. */
function queryOf(query: Query): Record<string, Record<string, string>> {
    const filter = Object.fromEntries(
        Object.entries(query).filter(([, value]) => value !== ''),
    );

    return Object.keys(filter).length ? { filter } : {};
}

function formatDate(date: string): string {
    return new Date(`${date}T00:00:00`).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

/**
 * The property Payouts Bookings tab: the next and last payout, then one row per booking with money or commission due
 * in the period. See docs/30-commission-fees/06-property-payouts.md#bookings-tab.
 */
export default function PayoutsIndex({
    rows,
    footer,
    summary,
    filters,
    periods,
    services,
    canOpenReservations,
}: Props) {
    const query: Query = {
        period: filters.period === 'month' ? '' : filters.period,
        date: filters.date,
        service: filters.service ?? '',
        search: filters.search ?? '',
    };

    const visit = (changes: Partial<Query>) => {
        router.get(
            index.url(undefined, {
                query: queryOf({ ...query, ...changes }),
            }),
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const columns = useMemo(
        () =>
            commissionBookingColumns(services, {
                withProperty: false,
                currency: summary.currency,
            }),
        [services, summary.currency],
    );

    const money = (amount: string) => (
        <MoneyText amount={amount} currency={summary.currency} plain />
    );
    const owesEstatell = summary.nextAmount.startsWith('-');
    const hasFilters = Boolean(query.service || query.search);

    return (
        <>
            <Head title="Payouts" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Payouts"
                    description="Money Estatell collects for the property, commission per service and payouts."
                    actions={
                        <a
                            href={exportMethod.url(undefined, {
                                query: queryOf(query),
                            })}
                            className={buttonVariants({ variant: 'outline' })}
                        >
                            <FileDown aria-hidden />
                            Export CSV
                        </a>
                    }
                />

                <PayoutTabs activeTab="bookings">
                    <div className="flex flex-col gap-4">
                        <div className="grid gap-4 md:grid-cols-2">
                            <TotalCard
                                title={
                                    owesEstatell
                                        ? 'You owe Estatell'
                                        : 'Next payout'
                                }
                                amount={
                                    owesEstatell
                                        ? summary.nextAmount.slice(1)
                                        : summary.nextAmount
                                }
                                note={`Due ${formatDate(summary.nextDueOn)}`}
                                currency={summary.currency}
                            />
                            <TotalCard
                                title="Last payout"
                                amount={summary.lastAmount ?? '0.00'}
                                note={
                                    summary.lastPaidOn
                                        ? `Paid ${formatDate(summary.lastPaidOn)}${summary.lastReference ? ` · ref ${summary.lastReference}` : ''}`
                                        : 'No payout yet'
                                }
                                currency={summary.currency}
                            />
                        </div>

                        <FilterBar
                            search={query.search}
                            onSearchChange={(search) => visit({ search })}
                            searchPlaceholder="Search booking or guest"
                        >
                            <EnumSelect
                                options={periods}
                                value={filters.period}
                                onValueChange={(period) =>
                                    visit({
                                        period:
                                            period === 'month' ? '' : period,
                                    })
                                }
                                aria-label="Period"
                                className="w-36"
                            />
                            <DatePicker
                                value={filters.date}
                                onChange={(date) => date && visit({ date })}
                                aria-label="Period date"
                                className="w-44"
                            />
                            <span className="text-sm text-muted-foreground">
                                <DateText date={filters.from} /> –{' '}
                                <DateText date={filters.to} />
                            </span>
                            <EnumSelect
                                options={services}
                                value={query.service}
                                onValueChange={(service) => visit({ service })}
                                allLabel="All services"
                                aria-label="Service"
                                className="w-48"
                            />
                        </FilterBar>

                        <DataTable
                            columns={columns}
                            data={rows.data}
                            getRowId={(row) => String(row.reservationId)}
                            columnAlign={commissionBookingAlign(services)}
                            sort=""
                            onSortChange={() => {}}
                            onRowClick={
                                canOpenReservations
                                    ? (row) =>
                                          router.visit(
                                              reservation.url({
                                                  reservation:
                                                      row.reservationId,
                                              }),
                                          )
                                    : undefined
                            }
                            footer={{
                                bookingReference: 'Total',
                                value: money(footer.value),
                                ...Object.fromEntries(
                                    services.map((service) => [
                                        service.value,
                                        money(
                                            footer.commissions[service.value] ??
                                                '0.00',
                                        ),
                                    ]),
                                ),
                                outstanding: money(footer.outstanding),
                            }}
                            pagination={{
                                currentPage: rows.meta.current_page,
                                lastPage: rows.meta.last_page,
                                from: rows.meta.from,
                                to: rows.meta.to,
                                total: rows.meta.total,
                                prevUrl: rows.meta.prev_page_url,
                                nextUrl: rows.meta.next_page_url,
                            }}
                            empty={
                                <EmptyState
                                    icon={Rows3}
                                    title={
                                        hasFilters
                                            ? 'No bookings match these filters'
                                            : 'No bookings in this period'
                                    }
                                    description="A booking shows here once it has ended and its money or commission is recorded."
                                />
                            }
                        />
                    </div>
                </PayoutTabs>
            </div>
        </>
    );
}

PayoutsIndex.layout = {
    breadcrumbs: withTabCrumb(
        [{ title: 'Payouts', href: index() }],
        payoutTabs(),
        'bookings',
    ),
};
