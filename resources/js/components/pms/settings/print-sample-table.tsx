import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type PrintSampleColumn = {
    key: string;
    label: string;
    align?: 'right';
    className?: string;
};

/** A row under the lines: a label over all but the last column, then the amount. */
export type PrintSampleSummaryRow = { label: ReactNode; amount: ReactNode };

export type PrintSampleGroup = {
    heading?: string;
    rows: Record<string, ReactNode>[];
};

/**
 * A browser drawing of a PDF table in the property's table style, like `pdf/layout.blade.php` draws its `.rule` rows:
 * Minimal puts a thin line under each row, Lined a border around every cell, and Shaded a grey header with every
 * other row tinted. The total row, like the PDF's `.total` row, has a strong line on top in every style, the cell borders
 * when Lined and the header grey when Shaded. Summary rows (the taxes) stay plain.
 */
export function PrintSampleTable({
    tableStyle,
    columns,
    groups,
    showHead = true,
    summary = [],
    total,
    summaryAlign = 'left',
    className,
}: {
    tableStyle: App.Enums.PdfTableStyle;
    columns: PrintSampleColumn[];
    groups: PrintSampleGroup[];
    showHead?: boolean;
    summary?: PrintSampleSummaryRow[];
    total?: PrintSampleSummaryRow;
    /** The document lines right-align the summary labels next to the amounts; a report starts them at the left. */
    summaryAlign?: 'left' | 'right';
    className?: string;
}) {
    const minimal = tableStyle === 'minimal';
    const cell = (column?: PrintSampleColumn) =>
        cn(
            'py-1 text-left align-top',
            minimal ? 'pr-1 last:pr-0' : 'px-1',
            tableStyle === 'lined' && 'border',
            column?.align === 'right' && 'text-right whitespace-nowrap',
            column?.className,
        );
    // Like the PDF's `tr.rule:nth-child(even) td`: every row counts, heading rows included, and only data cells tint.
    let position = 0;
    const row = () => {
        position += 1;

        return cn(
            minimal && 'border-b',
            tableStyle === 'shaded' && position % 2 === 0 && 'bg-muted/60',
        );
    };
    const heading = cn('font-bold', tableStyle === 'shaded' && 'bg-muted');
    const amountColumn = columns[columns.length - 1];
    const summaryCell = (strong: boolean) =>
        cn(
            'py-1 align-top',
            minimal ? 'pr-1 last:pr-0' : 'px-1',
            strong &&
                cn(
                    'border-t border-t-foreground pt-2 font-bold',
                    tableStyle === 'lined' && 'border-x border-b',
                    tableStyle === 'shaded' && 'bg-muted',
                ),
        );
    const summaryRow = (
        line: PrintSampleSummaryRow,
        strong: boolean,
        key: string,
    ) => (
        <tr key={key}>
            <td
                colSpan={columns.length - 1}
                className={cn(
                    summaryCell(strong),
                    summaryAlign === 'right' && 'text-right',
                )}
            >
                {line.label}
            </td>
            <td
                className={cn(
                    summaryCell(strong),
                    'text-right whitespace-nowrap',
                    amountColumn?.className,
                )}
            >
                {line.amount}
            </td>
        </tr>
    );
    return (
        <table className={cn('w-full border-collapse tabular-nums', className)}>
            {showHead && (
                <thead>
                    <tr className={cn(minimal && 'border-b')}>
                        {columns.map((column) => (
                            <th
                                key={column.key}
                                className={cn(cell(column), heading)}
                            >
                                {column.label}
                            </th>
                        ))}
                    </tr>
                </thead>
            )}
            <tbody>
                {groups.flatMap((group, groupIndex) => [
                    ...(group.heading
                        ? [
                              <tr
                                  key={`heading-${groupIndex}`}
                                  className={cn(
                                      row(),
                                      tableStyle === 'shaded' &&
                                          'bg-transparent',
                                  )}
                              >
                                  <th
                                      colSpan={columns.length}
                                      className={cn(cell(), heading)}
                                  >
                                      {group.heading}
                                  </th>
                              </tr>,
                          ]
                        : []),
                    ...group.rows.map((cells, rowIndex) => (
                        <tr key={`${groupIndex}-${rowIndex}`} className={row()}>
                            {columns.map((column) => (
                                <td key={column.key} className={cell(column)}>
                                    {cells[column.key]}
                                </td>
                            ))}
                        </tr>
                    )),
                ])}
            </tbody>
            {(summary.length > 0 || total) && (
                <tfoot>
                    {summary.map((line, index) =>
                        summaryRow(line, false, `summary-${index}`),
                    )}
                    {total && summaryRow(total, true, 'total')}
                </tfoot>
            )}
        </table>
    );
}
