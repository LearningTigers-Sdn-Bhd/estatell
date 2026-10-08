import { Head, router, usePage } from '@inertiajs/react';
import { Settings2 } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { BulkActionBar } from '@/components/pms/bulk-action-bar';
import { DataTable, dataTableColumns } from '@/components/pms/data-table';
import { DatePicker } from '@/components/pms/date-picker';
import { DateRangePicker } from '@/components/pms/date-range-picker';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { PageHeader } from '@/components/pms/page-header';
import { ReportExportMenu } from '@/components/pms/reports/report-export-menu';
import { ReportLayoutSheet } from '@/components/pms/reports/report-layout-sheet';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { toast } from '@/components/ui/toast';
import {
    reportDatePresets,
    reportDatePresetsForOneDate,
} from '@/lib/date-presets';

type Layout = App.Data.Reports.ReportLayoutData;
type Currency = App.Enums.Currency | null;

/** A cell renderer by column key. A column with no renderer shows its value, or a dash when it is empty. */
export type ReportCells<TRow> = Record<
    string,
    (row: TRow, currency: Currency) => ReactNode
>;

/** A footer cell renderer by column key. It gets the figure of the total row (a count, or a decimal string). */
export type ReportTotalCells = Record<
    string,
    (figure: number | string, currency: Currency) => ReactNode
>;

type Props<TRow extends { key: number | string }> = {
    report: App.Enums.ReportKey;
    title: string;
    description: string;
    emptyIcon: LucideIcon;
    emptyTitle: string;
    emptyDescription: string;
    rows: Illuminate.LengthAwarePaginator<number, TRow>;
    sort: string;
    layout: Layout;
    canEditLayout: boolean;
    orientationOptions: EnumOption[];
    /** The first date. For a report of one date (`maxRangeDays` is null) this is the date. */
    from: string;
    to: string;
    maxRangeDays: number | null;
    currency: Currency;
    cells?: ReportCells<TRow>;
    /** The total row of the report, when it has one. It shows under the table in the visible columns. */
    totals?: App.Data.Reports.ReportTotals | null;
    totalCells?: ReportTotalCells;
    /** The grouping of a report that takes one, with its options for the Day / Month toggle. */
    group?: App.Enums.ReportGroup | null;
    groupOptions?: EnumOption[];
    /** The transaction code of a report that filters by one (`''` or null for every code), with its options. */
    code?: string | null;
    codeOptions?: EnumOption[];
    /** The staff filter of Payments (the user id as text, `''` or null for all staff), with its options, and Refunds only. */
    staff?: string | null;
    staffOptions?: EnumOption[];
    refundsOnly?: boolean;
    /** The Foreign only filter of the Police report. */
    foreignOnly?: boolean;
    /** A short note under the title, such as "Status as of today". */
    note?: string | null;
    /** A small summary table under the main table (Payments: the total for each method). */
    breakdown?: App.Data.Reports.ReportBreakdown | null;
    /** The quick dates of a range report, from `ReportKey::datePresets()`. Without it the standard list shows. */
    presets?: App.Enums.ReportDatePreset[];
};

const MAX_SELECTED = 500;

/**
 * The page of a report: the header, the dates, Layout and Export, the table with the resolved layout, row selection
 * with the floating bar, and the Layout Sheet. A report page passes its text and the cell renderers of the columns
 * that need more than text.
 */
export function ReportScreen<TRow extends { key: number | string }>({
    report,
    title,
    description,
    emptyIcon,
    emptyTitle,
    emptyDescription,
    rows,
    sort,
    layout,
    canEditLayout,
    orientationOptions,
    from,
    to,
    maxRangeDays,
    currency,
    cells = {},
    totals = null,
    totalCells = {},
    group = null,
    groupOptions = [],
    code = null,
    codeOptions = [],
    staff = null,
    staffOptions = [],
    refundsOnly,
    foreignOnly,
    breakdown = null,
    note = null,
    presets,
}: Props<TRow>) {
    const column = useMemo(() => dataTableColumns<TRow>(), []);
    const [draft, setDraft] = useState<Layout | null>(null);
    const [layoutOpen, setLayoutOpen] = useState(false);
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const clearSelection = useCallback(() => setSelected(new Set()), []);
    const active = draft ?? layout;
    const isOneDate = maxRangeDays === null;
    const dates = useMemo(
        (): Record<string, string> => ({
            ...(isOneDate ? { date: from } : { from, to }),
            ...(group ? { group } : {}),
            ...(code ? { code } : {}),
            ...(staff ? { staff } : {}),
            ...(refundsOnly ? { refunds: '1' } : {}),
            ...(foreignOnly ? { foreign: '1' } : {}),
        }),
        [isOneDate, from, to, group, code, staff, refundsOnly, foreignOnly],
    );

    const visible = useMemo(
        () => active.columns.filter((item) => item.visible),
        [active],
    );

    const openLayout = () => {
        setDraft(layout);
        setLayoutOpen(true);
    };

    const closeLayout = () => {
        setLayoutOpen(false);
        setDraft(null);
    };

    const tableColumns = useMemo(
        () =>
            visible.map((item) =>
                column.accessor(
                    (row) => {
                        const value = (row as Record<string, unknown>)[
                            item.key
                        ];

                        return typeof value === 'object' && value !== null
                            ? (value as { label: string }).label
                            : (value as string | number | null);
                    },
                    {
                        id: item.key,
                        header: item.label,
                        cell: (info) => {
                            const render = cells[item.key];

                            if (render) {
                                return render(info.row.original, currency);
                            }

                            const value = info.getValue();

                            return value === null ||
                                value === undefined ||
                                value === '' ? (
                                <span className="text-muted-foreground">—</span>
                            ) : (
                                value
                            );
                        },
                    },
                ),
            ),
        [column, visible, cells, currency],
    );

    const columnAlign = useMemo(
        () => Object.fromEntries(visible.map((item) => [item.key, item.align])),
        [visible],
    );

    const { url, props } = usePage();
    const businessDay = props.businessDate?.date;
    const rangePresets = useMemo(
        () => (businessDay ? reportDatePresets(businessDay, presets) : []),
        [businessDay, presets],
    );
    const datePresets = useMemo(
        () => (businessDay ? reportDatePresetsForOneDate(businessDay) : []),
        [businessDay],
    );

    const footer = useMemo((): Record<string, ReactNode> | undefined => {
        if (!totals) {
            return undefined;
        }

        const labelKey = visible.find(
            (item) => (totals.figures[item.key] ?? null) === null,
        )?.key;

        return Object.fromEntries(
            visible.map((item) => {
                const figure = totals.figures[item.key];

                if (figure === null || figure === undefined) {
                    return [item.key, item.key === labelKey ? 'Total' : null];
                }

                const render = totalCells[item.key];

                return [item.key, render ? render(figure, currency) : figure];
            }),
        );
    }, [totals, visible, totalCells, currency]);

    const visit = useCallback(
        (changes: {
            from?: string;
            to?: string;
            sort?: string;
            group?: App.Enums.ReportGroup;
            code?: string;
            staff?: string;
            refundsOnly?: boolean;
            foreignOnly?: boolean;
        }) => {
            const next = {
                from,
                to,
                sort,
                group,
                code: code ?? '',
                staff: staff ?? '',
                refundsOnly: refundsOnly ?? false,
                foreignOnly: foreignOnly ?? false,
                ...changes,
            };

            if (
                next.from !== from ||
                next.to !== to ||
                next.group !== group ||
                next.code !== (code ?? '') ||
                next.staff !== (staff ?? '') ||
                next.refundsOnly !== (refundsOnly ?? false) ||
                next.foreignOnly !== (foreignOnly ?? false)
            ) {
                setSelected(new Set());
            }

            router.get(
                new URL(url, window.location.origin).pathname,
                {
                    ...(isOneDate
                        ? { date: next.from }
                        : { from: next.from, to: next.to }),
                    ...(next.group ? { group: next.group } : {}),
                    ...(next.code ? { code: next.code } : {}),
                    ...(next.staff ? { staff: next.staff } : {}),
                    ...(next.refundsOnly ? { refunds: '1' } : {}),
                    ...(next.foreignOnly ? { foreign: '1' } : {}),
                    ...(next.sort ? { sort: next.sort } : {}),
                },
                { preserveState: true, preserveScroll: true, replace: true },
            );
        },
        [
            url,
            isOneDate,
            from,
            to,
            sort,
            group,
            code,
            staff,
            refundsOnly,
            foreignOnly,
        ],
    );

    const changeSelection = (next: Set<string>) => {
        if (next.size > MAX_SELECTED) {
            toast.add({
                title: `You can select up to ${MAX_SELECTED} rows`,
                type: 'error',
            });

            return;
        }

        setSelected(next);
    };

    return (
        <>
            <Head title={title} />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader title={title} description={description} />

                {note && (
                    <p className="-mt-2 text-sm text-muted-foreground">
                        {note}
                    </p>
                )}

                <div className="flex flex-wrap items-center gap-2">
                    {isOneDate ? (
                        <DatePicker
                            value={from}
                            onChange={(next) =>
                                next && visit({ from: next, to: next })
                            }
                            presets={datePresets}
                            className="w-auto"
                        />
                    ) : (
                        <DateRangePicker
                            from={from}
                            to={to}
                            onChange={(nextFrom, nextTo) =>
                                visit({ from: nextFrom, to: nextTo })
                            }
                            presets={rangePresets}
                            maxDays={maxRangeDays}
                            weekStartsOn={1}
                            className="w-auto"
                        />
                    )}
                    {group && groupOptions.length > 0 && (
                        <ToggleGroup
                            variant="outline"
                            spacing={0}
                            aria-label="Group by"
                            value={[group]}
                            onValueChange={(values) => {
                                const next = groupOptions.find(
                                    (option) => option.value === values[0],
                                );

                                if (next) {
                                    visit({
                                        group: next.value as App.Enums.ReportGroup,
                                    });
                                }
                            }}
                        >
                            {groupOptions.map((option) => (
                                <ToggleGroupItem
                                    key={option.value}
                                    value={option.value}
                                >
                                    {option.label}
                                </ToggleGroupItem>
                            ))}
                        </ToggleGroup>
                    )}
                    {codeOptions.length > 0 && (
                        <EnumSelect
                            options={codeOptions}
                            allLabel="All codes"
                            value={code ?? ''}
                            onValueChange={(next) => visit({ code: next })}
                            aria-label="Transaction code"
                            className="w-auto"
                        />
                    )}
                    {(staffOptions.length > 0 || staff) && (
                        <EnumSelect
                            options={staffOptions}
                            allLabel="All staff"
                            value={staff ?? ''}
                            onValueChange={(next) => visit({ staff: next })}
                            aria-label="Staff"
                            className="w-auto"
                        />
                    )}
                    {refundsOnly !== undefined ? (
                        <Label className="flex items-center gap-2 text-sm font-normal">
                            <Checkbox
                                checked={refundsOnly}
                                onCheckedChange={(checked) =>
                                    visit({ refundsOnly: checked })
                                }
                            />
                            Refunds only
                        </Label>
                    ) : null}
                    {foreignOnly !== undefined ? (
                        <Label className="flex items-center gap-2 text-sm font-normal">
                            <Checkbox
                                checked={foreignOnly}
                                onCheckedChange={(checked) =>
                                    visit({ foreignOnly: checked })
                                }
                            />
                            Foreign only
                        </Label>
                    ) : null}
                    <div className="ml-auto flex items-center gap-2">
                        {canEditLayout && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={openLayout}
                            >
                                <Settings2 data-icon="inline-start" />
                                Layout
                            </Button>
                        )}
                        <ReportExportMenu
                            report={report}
                            dates={dates}
                            sort={sort}
                            rowKeys={[...selected]}
                        />
                    </div>
                </div>

                <DataTable
                    columns={tableColumns}
                    data={rows.data}
                    getRowId={(row) => String(row.key)}
                    sort={sort}
                    onSortChange={(next) => visit({ sort: next })}
                    columnAlign={columnAlign}
                    selection={{ selected, onChange: changeSelection }}
                    footer={footer}
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
                            icon={emptyIcon}
                            title={emptyTitle}
                            description={emptyDescription}
                        />
                    }
                />

                {breakdown && breakdown.rows.length > 0 && (
                    <div className="flex flex-col gap-2">
                        <h2 className="text-sm font-medium">
                            {breakdown.title}
                        </h2>
                        <div className="max-w-2xl overflow-hidden rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        {breakdown.headings.map(
                                            (heading, index) => (
                                                <TableHead
                                                    key={heading}
                                                    className={
                                                        index > 0
                                                            ? 'text-right'
                                                            : undefined
                                                    }
                                                >
                                                    {heading}
                                                </TableHead>
                                            ),
                                        )}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {breakdown.rows.map((line, lineIndex) => (
                                        <TableRow
                                            key={lineIndex}
                                            className={
                                                breakdown.subtotalRows.includes(
                                                    lineIndex,
                                                )
                                                    ? 'bg-muted/50 font-medium'
                                                    : undefined
                                            }
                                        >
                                            {line.map((cell, index) => (
                                                <TableCell
                                                    key={index}
                                                    className={
                                                        index > 0
                                                            ? 'text-right tabular-nums'
                                                            : undefined
                                                    }
                                                >
                                                    {cell}
                                                </TableCell>
                                            ))}
                                        </TableRow>
                                    ))}
                                </TableBody>
                                <TableFooter>
                                    <TableRow>
                                        {breakdown.total.map((cell, index) => (
                                            <TableCell
                                                key={index}
                                                className={
                                                    index > 0
                                                        ? 'text-right tabular-nums'
                                                        : undefined
                                                }
                                            >
                                                {cell}
                                            </TableCell>
                                        ))}
                                    </TableRow>
                                </TableFooter>
                            </Table>
                        </div>
                    </div>
                )}

                <BulkActionBar count={selected.size} onClear={clearSelection}>
                    <ReportExportMenu
                        report={report}
                        dates={dates}
                        sort={sort}
                        rowKeys={[...selected]}
                        side="top"
                    />
                </BulkActionBar>
            </div>

            {canEditLayout && draft && (
                <ReportLayoutSheet
                    open={layoutOpen}
                    onOpenChange={(next) => {
                        if (next) {
                            setLayoutOpen(true);
                        } else {
                            closeLayout();
                        }
                    }}
                    report={report}
                    reportLabel={title}
                    saved={layout}
                    draft={draft}
                    onDraftChange={setDraft}
                    orientations={orientationOptions}
                    dates={dates}
                    onDone={closeLayout}
                />
            )}
        </>
    );
}
