import { Fragment } from 'react';
import { DateText } from '@/components/pms/date-text';
import { MoneyText } from '@/components/pms/money-text';
import { Badge } from '@/components/ui/badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';

type Row = App.Data.Folio.FolioRowData;

const SECTIONS: Record<Row['state'], string> = {
    posted: 'Posted',
    scheduled: 'Scheduled',
};

/**
 * The Posted rows, then the Scheduled rows, as the server sent them. Scheduled rows are muted, and children (a
 * discount, then its tax) are indented. The Unit column shows only when a row names a unit.
 */
export function FolioRowsTable({
    rows,
    currency,
}: {
    rows: Row[];
    currency: App.Enums.Currency | null;
}) {
    const showUnit = rows.some((row) => row.unit !== null);
    const columns = showUnit ? 4 : 3;

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Date</TableHead>
                    <TableHead>Description</TableHead>
                    {showUnit && <TableHead>Unit</TableHead>}
                    <TableHead className="text-right">Amount</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {rows.map((row, index) => (
                    <Fragment key={row.key}>
                        {row.state !== rows[index - 1]?.state && (
                            <TableRow className="bg-muted/50 hover:bg-muted/50">
                                <TableCell
                                    colSpan={columns}
                                    className="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                                >
                                    {SECTIONS[row.state as Row['state']]}
                                </TableCell>
                            </TableRow>
                        )}
                        <TableRow
                            className={cn(
                                row.state === 'scheduled' &&
                                    'text-muted-foreground',
                            )}
                        >
                            <TableCell>
                                {row.level === 0 && (
                                    <DateText date={row.date} />
                                )}
                            </TableCell>
                            <TableCell>
                                <span
                                    className={cn(
                                        'flex flex-wrap items-center gap-2',
                                        row.level === 1 && 'pl-4',
                                        row.level >= 2 && 'pl-8',
                                    )}
                                >
                                    {row.level > 0 && (
                                        <span aria-hidden>↳</span>
                                    )}
                                    {row.description}
                                    {row.tags.map((tag) => (
                                        <Badge key={tag} variant="outline">
                                            {tag}
                                        </Badge>
                                    ))}
                                </span>
                            </TableCell>
                            {showUnit && <TableCell>{row.unit}</TableCell>}
                            <TableCell className="text-right">
                                <MoneyText
                                    amount={row.amount}
                                    currency={currency}
                                />
                            </TableCell>
                        </TableRow>
                    </Fragment>
                ))}
            </TableBody>
        </Table>
    );
}
