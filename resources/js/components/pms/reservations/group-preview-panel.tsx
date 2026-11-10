import { Receipt } from 'lucide-react';
import type { ReactNode } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { cn } from '@/lib/utils';

export type GroupPreviewRow = {
    key: number;
    cells: ReactNode[];
};

/**
 * The muted Preview panel on the right of the group Dialogs (group add-on, group price): a table with one row per
 * unit whose header and total stay in place while only the rows scroll. `columns` is the grid template of the
 * header and the rows; the first column is the unit, the others align right. `rows` null shows the empty state.
 */
export function GroupPreviewPanel({
    columns,
    head,
    rows,
    total,
    emptyDescription,
    note,
}: {
    columns: string;
    head: string[];
    rows: GroupPreviewRow[] | null;
    total: { label: string; value: ReactNode };
    emptyDescription: string;
    note?: ReactNode;
}) {
    return (
        <div className="flex min-w-0 flex-col gap-2 rounded-lg border bg-muted p-3">
            <div className="flex items-baseline justify-between gap-2">
                <span className="text-sm font-medium">Preview</span>
                {rows !== null && (
                    <span className="text-xs text-muted-foreground">
                        {rows.length} {rows.length === 1 ? 'unit' : 'units'}
                    </span>
                )}
            </div>
            {rows === null ? (
                <div className="flex flex-1 items-center justify-center">
                    <EmptyState
                        icon={Receipt}
                        title="No preview yet"
                        description={emptyDescription}
                    />
                </div>
            ) : (
                <div className="flex flex-col overflow-hidden rounded-md border bg-background text-sm">
                    <div
                        className={cn(
                            'grid gap-2 border-b px-2 py-1.5 font-medium',
                            columns,
                        )}
                    >
                        {head.map((label, index) => (
                            <span
                                key={label}
                                className={index > 0 ? 'text-right' : undefined}
                            >
                                {label}
                            </span>
                        ))}
                    </div>
                    <div className="max-h-56 divide-y overflow-y-auto">
                        {rows.map((row) => (
                            <div
                                key={row.key}
                                className={cn(
                                    'grid gap-2 px-2 py-1.5',
                                    columns,
                                )}
                            >
                                {row.cells.map((cell, index) => (
                                    <span
                                        key={index}
                                        className={
                                            index > 0
                                                ? 'text-right tabular-nums'
                                                : 'truncate'
                                        }
                                    >
                                        {cell}
                                    </span>
                                ))}
                            </div>
                        ))}
                    </div>
                    <div className="flex items-center justify-between gap-2 border-t bg-muted/50 px-2 py-1.5 font-medium">
                        <span>{total.label}</span>
                        <span className="tabular-nums">{total.value}</span>
                    </div>
                </div>
            )}
            {note !== undefined && (
                <div className="text-xs text-muted-foreground">{note}</div>
            )}
        </div>
    );
}
