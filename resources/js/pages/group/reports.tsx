import { Head, Link, router } from '@inertiajs/react';
import { Building2, CalendarDays, History } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Fragment, useMemo } from 'react';
import { DateRangePicker } from '@/components/pms/date-range-picker';
import { DateText, formatDate } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { MoneyText } from '@/components/pms/money-text';
import { GroupExportMenu } from '@/components/pms/group/group-export-menu';
import {
    PropertySectionRow,
    startsProperty,
} from '@/components/pms/group/property-section-row';
import { RoomStatsChart } from '@/components/pms/reports/room-stats-chart';
import { StatusBadge } from '@/components/pms/status-badge';
import { TabLinks } from '@/components/pms/tab-links';
import type { TabLink } from '@/components/pms/tab-links';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { reportDatePresets } from '@/lib/date-presets';
import { dashboard as groupDashboard, reports } from '@/routes/group';
import {
    exportMethod as exportCsv,
    pdf as exportPdf,
} from '@/routes/group/reports';
import { historyForecast } from '@/routes/reports';
import { show } from '@/routes/reservations';

type Group = { id: number; name: string };
type Report = App.Data.Group.GroupReportsData;
type Figures = App.Data.Group.GroupFiguresRowData;
type Currency = App.Data.Property.CurrencyData | null;

const icons: Record<string, LucideIcon> = {
    'by-property': Building2,
    'by-date': CalendarDays,
    bookings: History,
};

const descriptions: Record<string, string> = {
    'by-property':
        'Each property’s History & forecast for the dates, and the group. Occupancy, ADR and RevPAR are worked out from the sums.',
    'by-date':
        'The properties added up date by date. Each property counts a date as actual or forecast by its own business date.',
    bookings:
        'Every booking made, cancelled, moved or missed in the dates, at every property.',
};

/** A money cell: plain, as the column header carries the currency; a dash when the currencies differ. */
function money(amount: string | null, currency: Currency) {
    return amount === null ? (
        <span className="text-muted-foreground">—</span>
    ) : (
        <MoneyText amount={amount} currency={currency} plain />
    );
}

function signed(value: string | number, decimals: number) {
    const number = Number(value);

    return `${number > 0 ? '+' : ''}${number.toLocaleString('en-GB', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    })}`;
}

function FiguresTable({
    rows,
    total,
    currency,
    firstHeading,
    from,
    to,
}: {
    rows: Figures[];
    total: Figures | null;
    currency: Currency;
    firstHeading: string;
    from: string;
    to: string;
}) {
    const unit = currency ? ` (${currency.code})` : '';
    const cells = (row: Figures) => [
        row.available,
        row.sold,
        row.complimentary,
        `${Number(row.occupancy).toFixed(2)}%`,
        money(row.adr, currency),
        money(row.revpar, currency),
        row.pax,
        money(row.roomCharges, currency),
        money(row.extraCharges, currency),
        money(row.tax, currency),
        money(row.receipts, currency),
    ];

    return (
        <div className="overflow-x-auto rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        {[
                            firstHeading,
                            'Available',
                            'Sold',
                            'Complimentary',
                            'Occupancy',
                            `ADR${unit}`,
                            `RevPAR${unit}`,
                            'Pax',
                            `Room charges${unit}`,
                            `Extra charges${unit}`,
                            `Tax${unit}`,
                            `Receipts${unit}`,
                        ].map((heading, index) => (
                            <TableHead
                                key={heading}
                                className={
                                    index === 0 ? undefined : 'text-right'
                                }
                            >
                                {heading}
                            </TableHead>
                        ))}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {rows.map((row) => (
                        <TableRow key={row.code ?? row.label}>
                            <TableCell className="font-medium">
                                {row.code ? (
                                    <Link
                                        href={historyForecast.url(
                                            { property: row.code },
                                            { query: { from, to } },
                                        )}
                                        className="underline-offset-2 hover:underline"
                                    >
                                        {row.label}
                                    </Link>
                                ) : (
                                    <DateText date={row.label} />
                                )}
                            </TableCell>
                            {cells(row).map((cell, index) => (
                                <TableCell
                                    key={index}
                                    className="text-right tabular-nums"
                                >
                                    {cell}
                                </TableCell>
                            ))}
                        </TableRow>
                    ))}
                </TableBody>
                {total && (
                    <TableFooter>
                        <TableRow>
                            <TableCell>{total.label}</TableCell>
                            {cells(total).map((cell, index) => (
                                <TableCell
                                    key={index}
                                    className="text-right tabular-nums"
                                >
                                    {cell}
                                </TableCell>
                            ))}
                        </TableRow>
                    </TableFooter>
                )}
            </Table>
        </div>
    );
}

function BookingsTable({
    rows,
    currency,
}: {
    rows: App.Data.Group.GroupBookingChangeRowData[];
    currency: Currency;
}) {
    const unit = currency ? ` (${currency.code})` : '';

    return (
        <div className="overflow-x-auto rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        {[
                            'Change',
                            'Time',
                            'Reservation',
                            'Guest',
                            'Room',
                            'Check-in',
                            'Check-out',
                            'Room nights change',
                            `Revenue change${unit}`,
                            'Source',
                            'Reason',
                        ].map((heading) => (
                            <TableHead key={heading}>{heading}</TableHead>
                        ))}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {rows.map(
                        (
                            { propertyCode, propertyName, change, canOpen },
                            index,
                        ) => (
                            <Fragment key={`${propertyCode}-${change.key}`}>
                                {startsProperty(
                                    rows,
                                    index,
                                    (item) => item.propertyCode,
                                ) && (
                                    <PropertySectionRow
                                        name={propertyName}
                                        code={propertyCode}
                                        colSpan={11}
                                    />
                                )}
                                <TableRow>
                                    <TableCell>
                                        <StatusBadge status={change.kind} />
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {formatDate(
                                            change.changed_at.slice(0, 10),
                                        )}{' '}
                                        {change.changed_at.slice(11)}
                                    </TableCell>
                                    <TableCell>
                                        {canOpen ? (
                                            <Link
                                                href={show.url({
                                                    property: propertyCode,
                                                    reservation:
                                                        change.reservation_id,
                                                })}
                                                className="underline-offset-2 hover:underline"
                                            >
                                                {change.reservation}
                                            </Link>
                                        ) : (
                                            change.reservation
                                        )}
                                    </TableCell>
                                    <TableCell>{change.guest}</TableCell>
                                    <TableCell>{change.room}</TableCell>
                                    <TableCell>
                                        <DateText date={change.arrival} />
                                    </TableCell>
                                    <TableCell>
                                        <DateText date={change.departure} />
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {signed(change.nights_change, 0)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {signed(change.revenue_change, 2)}
                                    </TableCell>
                                    <TableCell>{change.source}</TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {change.reason ?? ''}
                                    </TableCell>
                                </TableRow>
                            </Fragment>
                        ),
                    )}
                </TableBody>
            </Table>
        </div>
    );
}

export default function GroupReports({
    group,
    tab,
    tabs,
    from,
    to,
    maxRangeDays,
    report,
}: {
    group: Group;
    tab: string;
    tabs: { value: string; label: string }[];
    from: string;
    to: string;
    maxRangeDays: number;
    report: Report;
}) {
    const presets = useMemo(
        () => reportDatePresets(new Date().toISOString().slice(0, 10)),
        [],
    );
    const figuresTab = tab !== 'bookings';
    const tabLinks: TabLink[] = tabs.map((item) => ({
        value: item.value,
        label: item.label,
        icon: icons[item.value],
        href: reports.url(
            { propertyGroup: group.id },
            {
                query:
                    (item.value !== 'bookings') === figuresTab
                        ? { tab: item.value, from, to }
                        : { tab: item.value },
            },
        ),
    }));
    const empty =
        report.properties.length === 0
            ? 'Your role does not show reports at these properties.'
            : null;

    return (
        <>
            <Head title={`${group.name} performance`} />

            <div className="flex flex-1 flex-col gap-3 p-4">
                <TabLinks
                    activeTab={tab}
                    panelWidth="full"
                    className="flex-1 gap-4"
                    panelClassName="flex flex-col gap-4"
                    tabs={tabLinks}
                >
                    <div className="flex flex-wrap items-center gap-3">
                        <DateRangePicker
                            from={from}
                            to={to}
                            onChange={(nextFrom, nextTo) =>
                                router.get(
                                    reports.url({ propertyGroup: group.id }),
                                    { tab, from: nextFrom, to: nextTo },
                                    { preserveState: true, replace: true },
                                )
                            }
                            presets={presets}
                            maxDays={maxRangeDays}
                            weekStartsOn={1}
                            className="w-auto"
                        />
                        <span className="text-sm text-muted-foreground">
                            {descriptions[tab]}
                        </span>
                        <div className="ml-auto">
                            <GroupExportMenu
                                pdfUrl={exportPdf.url(
                                    { propertyGroup: group.id },
                                    { query: { tab, from, to } },
                                )}
                                csvUrl={exportCsv.url(
                                    { propertyGroup: group.id },
                                    { query: { tab, from, to } },
                                )}
                            />
                        </div>
                    </div>

                    {report.leftOut.length > 0 && (
                        <p className="text-sm text-muted-foreground">
                            Not included: {report.leftOut.join(', ')}. Your role
                            there does not show reports.
                        </p>
                    )}

                    {empty ? (
                        <p className="text-sm text-muted-foreground">{empty}</p>
                    ) : figuresTab ? (
                        <>
                            {report.chart && (
                                <RoomStatsChart
                                    chart={report.chart}
                                    currency={report.currency}
                                    revenueLabel="Room charges"
                                />
                            )}
                            <FiguresTable
                                rows={report.figures}
                                total={report.total}
                                currency={report.currency}
                                firstHeading={
                                    tab === 'by-date' ? 'Date' : 'Property'
                                }
                                from={from}
                                to={to}
                            />
                        </>
                    ) : report.bookings.length === 0 ? (
                        <EmptyState
                            icon={History}
                            title="No changes in these dates"
                            description="Pick other dates to see bookings, cancellations, amendments and no-shows."
                        />
                    ) : (
                        <BookingsTable
                            rows={report.bookings}
                            currency={report.currency}
                        />
                    )}
                </TabLinks>
            </div>
        </>
    );
}

GroupReports.layout = (props: { group: Group }) => ({
    breadcrumbs: [
        {
            title: props.group.name,
            href: groupDashboard({ propertyGroup: props.group.id }),
        },
        {
            title: 'Performance',
            href: reports({ propertyGroup: props.group.id }),
        },
    ],
});
