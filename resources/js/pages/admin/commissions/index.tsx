import { Head, router } from '@inertiajs/react';
import { FileDown, Rows3 } from 'lucide-react';
import { useMemo } from 'react';
import {
    commissionBookingAlign,
    commissionBookingColumns,
} from '@/components/pms/commissions/booking-columns';
import { CommissionTabs } from '@/components/pms/commissions/commission-tabs';
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
import { dashboard } from '@/routes/admin';
import { exportMethod, index } from '@/routes/admin/commissions';

type Row = App.Data.Commissions.CommissionBookingRowData;
type Filters = App.Data.Commissions.CommissionBookingFilters;
type Query = {
    period: string;
    date: string;
    property: string;
    service: string;
    search: string;
};

type Props = {
    rows: Illuminate.LengthAwarePaginator<number, Row>;
    footer: {
        value: string;
        commissions: Record<string, string>;
        outstanding: string;
    };
    totals: App.Data.Commissions.CommissionTotalsData;
    filters: Filters;
    periods: EnumOption[];
    services: EnumOption[];
    properties: EnumOption[];
};

/** The URL query of the filters, leaving out the defaults. */
function queryOf(query: Query): Record<string, Record<string, string>> {
    const filter = Object.fromEntries(
        Object.entries(query).filter(([, value]) => value !== ''),
    );

    return Object.keys(filter).length ? { filter } : {};
}

/**
 * The admin Commissions Bookings tab: money held, the next payout and what properties owe, then one row per booking with
 * ledger entries due in the period. See docs/30-commission-fees/05-platform-pages.md#bookings-tab.
 */
export default function CommissionsIndex({
    rows,
    footer,
    totals,
    filters,
    periods,
    services,
    properties,
}: Props) {
    const query: Query = {
        period: filters.period === 'month' ? '' : filters.period,
        date: filters.date,
        property: filters.propertyId === null ? '' : String(filters.propertyId),
        service: filters.service ?? '',
        search: filters.search ?? '',
    };

    const visit = (changes: Partial<Query>) => {
        router.get(
            index.url({ query: queryOf({ ...query, ...changes }) }),
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const columns = useMemo(
        () => commissionBookingColumns(services, { withProperty: true }),
        [services],
    );

    const money = (amount: string) => (
        <MoneyText amount={amount} currency={totals.currency} />
    );
    const hasFilters = Boolean(query.property || query.service || query.search);

    return (
        <>
            <Head title="Commissions" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Commissions"
                    description="Money Estatell holds for properties, commission per service and payouts."
                    actions={
                        <a
                            href={exportMethod.url({ query: queryOf(query) })}
                            className={buttonVariants({ variant: 'outline' })}
                        >
                            <FileDown aria-hidden />
                            Export CSV
                        </a>
                    }
                />

                <CommissionTabs activeTab="bookings">
                    <div className="flex flex-col gap-4">
                        <div className="grid gap-4 md:grid-cols-3">
                            <TotalCard
                                title="Total cash liability"
                                amount={totals.cashLiability}
                                note="Held for properties, not paid out"
                                currency={totals.currency}
                            />
                            <TotalCard
                                title="Immediate payable"
                                amount={totals.immediatePayable}
                                note={`Due ${new Date(`${totals.payableOn}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })}`}
                                currency={totals.currency}
                            />
                            <TotalCard
                                title="Owed to Estatell"
                                amount={totals.owedToEstatell}
                                note={`${totals.propertiesOwing} ${totals.propertiesOwing === 1 ? 'property' : 'properties'}`}
                                currency={totals.currency}
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
                                options={properties}
                                value={query.property}
                                onValueChange={(property) =>
                                    visit({ property })
                                }
                                allLabel="All properties"
                                aria-label="Property"
                                className="w-52"
                            />
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
                            footer={{
                                propertyName: 'Total',
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
                </CommissionTabs>
            </div>
        </>
    );
}

CommissionsIndex.layout = {
    breadcrumbs: [
        { title: 'Admin', href: dashboard() },
        { title: 'Commissions', href: index() },
    ],
};
