import { Head, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, FileText } from 'lucide-react';
import { Fragment, useMemo, useState } from 'react';
import { DatePicker } from '@/components/pms/date-picker';
import { DateRangePicker } from '@/components/pms/date-range-picker';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { GroupExportMenu } from '@/components/pms/group/group-export-menu';
import {
    PropertySectionRow,
    startsProperty,
} from '@/components/pms/group/property-section-row';
import {
    ReservationPeekSheet,
    type PeekTarget,
} from '@/components/pms/group/reservation-peek-sheet';
import { RevenueChart } from '@/components/pms/reports/revenue-chart';
import { RoomStatsChart } from '@/components/pms/reports/room-stats-chart';
import { TabLinks } from '@/components/pms/tab-links';
import { Button } from '@/components/ui/button';
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
import { navIcon } from '@/lib/navigation';
import { cn } from '@/lib/utils';
import { dashboard as groupDashboard, propertyReports } from '@/routes/group';
import {
    exportMethod as exportCsv,
    pdf as exportPdf,
} from '@/routes/group/property-reports';

type Group = { id: number; name: string };
type Result = App.Data.Group.GroupPropertyReportData;
type Filters = {
    from: string;
    to: string;
    group: string | null;
    property: string | null;
};
/** The property filter's value for every property. */
const ALL = '';
type Option = { value: string; label: string; icon: string };

/**
 * Group › Reports › a category (Front desk, Revenue, …): its reports as tabs, each run for every property at once, each
 * row with its property, then a total per property and for all of them. The exports have every row.
 * See docs/03-staff/05-group-mode.md#property-reports.
 */
export default function GroupPropertyReports({
    group,
    reports,
    oneDate,
    maxRangeDays,
    groups,
    filters,
    properties,
    today,
    result,
}: {
    group: Group;
    category: { value: string; label: string };
    reports: Option[];
    oneDate: boolean;
    maxRangeDays: number | null;
    groups: EnumOption[];
    filters: Filters;
    properties: App.Data.Property.PropertySummaryData[];
    today: string;
    result: Result;
}) {
    const [peek, setPeek] = useState<PeekTarget>(null);
    const presets = useMemo(() => reportDatePresets(today), [today]);
    const query = (
        next: Partial<Filters & { report: string; page: number }>,
    ) => {
        const merged = { report: result.report, ...filters, ...next };

        return {
            ...(merged.property ? { property: merged.property } : {}),
            ...(next.page && next.page > 1 ? { page: next.page } : {}),
            report: merged.report,
            ...(oneDate && next.report === undefined
                ? { date: merged.from }
                : next.report === undefined
                  ? { from: merged.from, to: merged.to }
                  : {}),
            ...(merged.group && next.report === undefined
                ? { group: merged.group }
                : {}),
        };
    };
    const visit = (next: Partial<Filters & { report: string; page: number }>) =>
        router.get(
            propertyReports.url({ propertyGroup: group.id }),
            query(next),
            { preserveState: true, preserveScroll: true, replace: true },
        );
    const currency = result.currency?.code ?? null;
    const heading = (column: App.Data.Reports.ReportLayoutColumnData) =>
        column.money && currency
            ? `${column.label} (${currency})`
            : column.label;
    const right = (column: App.Data.Reports.ReportLayoutColumnData) =>
        column.align === 'right' ? 'text-right tabular-nums' : undefined;
    // A total names its property in the first column, where its rows have a name or a date and the total has none.
    const footerCell = (
        row: App.Data.Group.GroupReportRowData,
        key: string,
        position: number,
    ) => {
        const cell = row.cells[key] === 'Total' ? '' : (row.cells[key] ?? '');

        if (position > 0) {
            return cell;
        }

        return cell === ''
            ? `${row.property} total`
            : `${row.property} total: ${cell}`;
    };

    return (
        <>
            <Head title={`${group.name} ${result.label}`} />

            <div className="flex flex-1 flex-col gap-3 p-4">
                <TabLinks
                    activeTab={result.report}
                    panelWidth="full"
                    className="flex-1 gap-3"
                    panelClassName="flex flex-col gap-3"
                    tabs={reports.map((report) => ({
                        value: report.value,
                        label: report.label,
                        icon: navIcon(report.icon) ?? FileText,
                        href: propertyReports.url(
                            { propertyGroup: group.id },
                            { query: { report: report.value } },
                        ),
                    }))}
                >
                    <div className="flex flex-wrap items-center gap-3">
                        {oneDate ? (
                            <DatePicker
                                value={filters.from}
                                onChange={(date) =>
                                    date && visit({ from: date })
                                }
                                className="w-auto"
                            />
                        ) : (
                            <DateRangePicker
                                from={filters.from}
                                to={filters.to}
                                onChange={(from, to) => visit({ from, to })}
                                presets={presets}
                                maxDays={maxRangeDays ?? undefined}
                                weekStartsOn={1}
                                className="w-auto"
                            />
                        )}
                        {properties.length > 1 && (
                            <EnumSelect
                                aria-label="Property"
                                className="w-52"
                                options={[
                                    { value: ALL, label: 'All properties' },
                                    ...properties.map((property) => ({
                                        value: property.code,
                                        label: property.name,
                                    })),
                                ]}
                                value={filters.property ?? ALL}
                                onValueChange={(value) =>
                                    visit({ property: value || null })
                                }
                            />
                        )}
                        {groups.length > 0 && (
                            <EnumSelect
                                aria-label="Group by"
                                className="w-40"
                                options={groups}
                                value={filters.group ?? groups[0].value}
                                onValueChange={(value) =>
                                    visit({ group: value })
                                }
                            />
                        )}
                        <div className="ml-auto">
                            <GroupExportMenu
                                pdfUrl={exportPdf.url(
                                    { propertyGroup: group.id },
                                    { query: query({}) },
                                )}
                                csvUrl={exportCsv.url(
                                    { propertyGroup: group.id },
                                    { query: query({}) },
                                )}
                            />
                        </div>
                    </div>

                    {result.leftOut.length > 0 && (
                        <p className="text-sm text-muted-foreground">
                            Not included: {result.leftOut.join(', ')}. Your role
                            there does not show reports.
                        </p>
                    )}
                    {result.notOffered.length > 0 && (
                        <p className="text-sm text-muted-foreground">
                            {result.notOffered.join(', ')} does not use this
                            report.
                        </p>
                    )}

                    {result.roomStatsChart && (
                        <RoomStatsChart
                            chart={result.roomStatsChart}
                            currency={result.currency}
                            revenueLabel="Room revenue"
                        />
                    )}
                    {result.revenueChart && (
                        <RevenueChart
                            chart={result.revenueChart}
                            currency={result.currency}
                        />
                    )}
                    {result.rows.length === 0 ? (
                        <EmptyState
                            icon={FileText}
                            title={`No ${result.label.toLowerCase()} rows`}
                            description="Pick other dates or another report."
                        />
                    ) : (
                        <>
                            <div className="overflow-x-auto rounded-lg border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            {result.columns.map((column) => (
                                                <TableHead
                                                    key={column.key}
                                                    className={right(column)}
                                                >
                                                    {heading(column)}
                                                </TableHead>
                                            ))}
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {result.rows.map((row, index) => {
                                            const peekAt =
                                                row.reservationId !== null &&
                                                row.code !== null
                                                    ? {
                                                          propertyCode:
                                                              row.code,
                                                          reservationId:
                                                              row.reservationId,
                                                      }
                                                    : null;

                                            return (
                                                <Fragment key={index}>
                                                    {startsProperty(
                                                        result.rows,
                                                        index,
                                                        (item) =>
                                                            item.code ??
                                                            item.property,
                                                    ) && (
                                                        <PropertySectionRow
                                                            name={row.property}
                                                            code={row.code}
                                                            colSpan={
                                                                result.columns
                                                                    .length
                                                            }
                                                        />
                                                    )}
                                                    <TableRow
                                                        className={cn(
                                                            peekAt &&
                                                                'cursor-pointer',
                                                        )}
                                                        onClick={() =>
                                                            peekAt &&
                                                            setPeek(peekAt)
                                                        }
                                                    >
                                                        {result.columns.map(
                                                            (
                                                                column,
                                                                position,
                                                            ) => (
                                                                <TableCell
                                                                    key={
                                                                        column.key
                                                                    }
                                                                    className={right(
                                                                        column,
                                                                    )}
                                                                >
                                                                    {position ===
                                                                        0 &&
                                                                    peekAt ? (
                                                                        <button
                                                                            type="button"
                                                                            className="text-left font-medium underline-offset-2 hover:underline focus-visible:underline focus-visible:outline-none"
                                                                            aria-label={`Show the booking at ${row.property}: ${Object.values(row.cells).slice(0, 2).join(', ')}`}
                                                                            onClick={(
                                                                                event,
                                                                            ) => {
                                                                                event.stopPropagation();
                                                                                setPeek(
                                                                                    peekAt,
                                                                                );
                                                                            }}
                                                                        >
                                                                            {row
                                                                                .cells[
                                                                                column
                                                                                    .key
                                                                            ] ||
                                                                                'Open'}
                                                                        </button>
                                                                    ) : (
                                                                        row
                                                                            .cells[
                                                                            column
                                                                                .key
                                                                        ]
                                                                    )}
                                                                </TableCell>
                                                            ),
                                                        )}
                                                    </TableRow>
                                                </Fragment>
                                            );
                                        })}
                                    </TableBody>
                                    {result.totals.length > 0 && (
                                        <TableFooter>
                                            {result.totals.map((row) => (
                                                <TableRow
                                                    key={row.code ?? 'all'}
                                                    className={cn(
                                                        row.code === null
                                                            ? 'border-t-2 border-foreground/20 bg-muted font-semibold hover:bg-muted'
                                                            : 'text-muted-foreground hover:bg-transparent',
                                                    )}
                                                >
                                                    {result.columns.map(
                                                        (column, position) => (
                                                            <TableCell
                                                                key={column.key}
                                                                className={cn(
                                                                    right(
                                                                        column,
                                                                    ),
                                                                    position ===
                                                                        0 &&
                                                                        'whitespace-nowrap',
                                                                )}
                                                            >
                                                                {footerCell(
                                                                    row,
                                                                    column.key,
                                                                    position,
                                                                )}
                                                            </TableCell>
                                                        ),
                                                    )}
                                                </TableRow>
                                            ))}
                                        </TableFooter>
                                    )}
                                </Table>
                            </div>
                            {result.pages > 1 && (
                                <div className="flex items-center justify-between gap-3 text-sm text-muted-foreground">
                                    <span className="tabular-nums">
                                        {(result.page - 1) * result.perPage + 1}
                                        –
                                        {(result.page - 1) * result.perPage +
                                            result.rows.length}{' '}
                                        of {result.rowCount} rows. The exports
                                        have them all.
                                    </span>
                                    <div className="flex items-center gap-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={result.page <= 1}
                                            onClick={() =>
                                                visit({ page: result.page - 1 })
                                            }
                                        >
                                            <ChevronLeft data-icon="inline-start" />
                                            Previous
                                        </Button>
                                        <span className="tabular-nums">
                                            Page {result.page} of {result.pages}
                                        </span>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={
                                                result.page >= result.pages
                                            }
                                            onClick={() =>
                                                visit({ page: result.page + 1 })
                                            }
                                        >
                                            Next
                                            <ChevronRight data-icon="inline-end" />
                                        </Button>
                                    </div>
                                </div>
                            )}
                        </>
                    )}
                </TabLinks>
            </div>
            <ReservationPeekSheet
                groupId={group.id}
                target={peek}
                onClose={() => setPeek(null)}
            />
        </>
    );
}

GroupPropertyReports.layout = (props: {
    group: Group;
    category: { value: string; label: string };
}) => ({
    breadcrumbs: [
        {
            title: props.group.name,
            href: groupDashboard({ propertyGroup: props.group.id }),
        },
        {
            title: props.category.label,
            href: propertyReports(
                { propertyGroup: props.group.id },
                { query: { category: props.category.value } },
            ),
        },
    ],
});
