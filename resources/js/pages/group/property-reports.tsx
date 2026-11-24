import { Head, Link, router } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import { useMemo } from 'react';
import { DatePicker } from '@/components/pms/date-picker';
import { DateRangePicker } from '@/components/pms/date-range-picker';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { GroupExportMenu } from '@/components/pms/group/group-export-menu';
import { TabLinks } from '@/components/pms/tab-links';
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
import { dashboard as propertyDashboard } from '@/routes';

type Group = { id: number; name: string };
type Result = App.Data.Group.GroupPropertyReportData;
type Filters = { from: string; to: string; group: string | null };
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
    today: string;
    result: Result;
}) {
    const presets = useMemo(() => reportDatePresets(today), [today]);
    const query = (next: Partial<Filters & { report: string }>) => {
        const merged = { report: result.report, ...filters, ...next };

        return {
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
    const visit = (next: Partial<Filters & { report: string }>) =>
        router.get(
            propertyReports.url({ propertyGroup: group.id }),
            query(next),
            { preserveState: true, replace: true },
        );
    const currency = result.currency?.code ?? null;
    const heading = (column: App.Data.Reports.ReportLayoutColumnData) =>
        column.money && currency
            ? `${column.label} (${currency})`
            : column.label;
    const right = (column: App.Data.Reports.ReportLayoutColumnData) =>
        column.align === 'right' ? 'text-right tabular-nums' : undefined;

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
                                            <TableHead>Property</TableHead>
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
                                        {result.rows.map((row, index) => (
                                            <TableRow key={index}>
                                                <TableCell className="font-medium whitespace-nowrap">
                                                    {row.code ? (
                                                        <Link
                                                            href={propertyDashboard.url(
                                                                {
                                                                    property:
                                                                        row.code,
                                                                },
                                                            )}
                                                            className="underline-offset-2 hover:underline"
                                                        >
                                                            {row.property}
                                                        </Link>
                                                    ) : (
                                                        row.property
                                                    )}
                                                </TableCell>
                                                {result.columns.map(
                                                    (column) => (
                                                        <TableCell
                                                            key={column.key}
                                                            className={right(
                                                                column,
                                                            )}
                                                        >
                                                            {
                                                                row.cells[
                                                                    column.key
                                                                ]
                                                            }
                                                        </TableCell>
                                                    ),
                                                )}
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                    {result.totals.length > 0 && (
                                        <TableFooter>
                                            {result.totals.map((row) => (
                                                <TableRow
                                                    key={row.code ?? 'all'}
                                                    className={cn(
                                                        row.code === null &&
                                                            'font-semibold',
                                                    )}
                                                >
                                                    <TableCell className="whitespace-nowrap">
                                                        {`${row.property} total`}
                                                    </TableCell>
                                                    {result.columns.map(
                                                        (column) => (
                                                            <TableCell
                                                                key={column.key}
                                                                className={right(
                                                                    column,
                                                                )}
                                                            >
                                                                {row.cells[
                                                                    column.key
                                                                ] === 'Total'
                                                                    ? ''
                                                                    : row.cells[
                                                                          column
                                                                              .key
                                                                      ]}
                                                            </TableCell>
                                                        ),
                                                    )}
                                                </TableRow>
                                            ))}
                                        </TableFooter>
                                    )}
                                </Table>
                            </div>
                            {result.rowCount > result.rows.length && (
                                <p className="text-sm text-muted-foreground">
                                    Showing the first {result.rows.length} of{' '}
                                    {result.rowCount} rows. Export CSV for all
                                    of them.
                                </p>
                            )}
                        </>
                    )}
                </TabLinks>
            </div>
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
