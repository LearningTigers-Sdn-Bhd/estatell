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
import type { EnumOption } from '@/components/pms/enum-select';
import { PageHeader } from '@/components/pms/page-header';
import { ReportExportMenu } from '@/components/pms/reports/report-export-menu';
import { ReportLayoutSheet } from '@/components/pms/reports/report-layout-sheet';
import { Button } from '@/components/ui/button';
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

type Props<TRow extends { key: number }> = {
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
};

const MAX_SELECTED = 500;

/**
 * The page of a report: the header, the dates, Layout and Export, the table with the resolved layout, row selection
 * with the floating bar, and the Layout Sheet. A report page passes its text and the cell renderers of the columns
 * that need more than text.
 */
export function ReportScreen<TRow extends { key: number }>({
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
}: Props<TRow>) {
    const column = useMemo(() => dataTableColumns<TRow>(), []);
    const [draft, setDraft] = useState<Layout | null>(null);
    const [layoutOpen, setLayoutOpen] = useState(false);
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const clearSelection = useCallback(() => setSelected(new Set()), []);
    const active = draft ?? layout;
    const isOneDate = maxRangeDays === null;
    const dates = useMemo(
        (): Record<string, string> =>
            isOneDate ? { date: from } : { from, to },
        [isOneDate, from, to],
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

                            return (
                                info.getValue() ?? (
                                    <span className="text-muted-foreground">
                                        —
                                    </span>
                                )
                            );
                        },
                    },
                ),
            ),
        [column, visible, cells, currency],
    );

    const rightAlign = useMemo(
        () =>
            visible
                .filter((item) => item.align === 'right')
                .map((item) => item.key),
        [visible],
    );

    const { url, props } = usePage();
    const businessDay = props.businessDate?.date;
    const rangePresets = useMemo(
        () => (businessDay ? reportDatePresets(businessDay) : []),
        [businessDay],
    );
    const datePresets = useMemo(
        () => (businessDay ? reportDatePresetsForOneDate(businessDay) : []),
        [businessDay],
    );

    const visit = useCallback(
        (changes: { from?: string; to?: string; sort?: string }) => {
            const next = { from, to, sort, ...changes };

            if (next.from !== from || next.to !== to) {
                setSelected(new Set());
            }

            router.get(
                new URL(url, window.location.origin).pathname,
                {
                    ...(isOneDate
                        ? { date: next.from }
                        : { from: next.from, to: next.to }),
                    ...(next.sort ? { sort: next.sort } : {}),
                },
                { preserveState: true, preserveScroll: true, replace: true },
            );
        },
        [url, isOneDate, from, to, sort],
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
                    rightAlign={rightAlign}
                    selection={{ selected, onChange: changeSelection }}
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
