import {
    ArrowRightLeft,
    MoreHorizontal,
    RotateCcw,
    Undo2,
    X,
} from 'lucide-react';
import { Fragment } from 'react';
import { DateText } from '@/components/pms/date-text';
import { MoneyText } from '@/components/pms/money-text';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
 * discount, then its tax) are indented. A payment shows its reference under the method. The Unit column shows only
 * when a row names a unit.
 */
export function FolioRowsTable({
    rows,
    currency,
    onAction,
}: {
    rows: Row[];
    currency: App.Enums.Currency | null;
    onAction: (
        row: Row,
        action: 'reverse' | 'transfer' | 'remove_discount' | 'refund',
    ) => void;
}) {
    const showUnit = rows.some((row) => row.unit !== null);
    const showActions = rows.some((row) => row.actions.length > 0);
    const columns = 3 + (showUnit ? 1 : 0) + (showActions ? 1 : 0);

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Date</TableHead>
                    <TableHead>Description</TableHead>
                    {showUnit && <TableHead>Unit</TableHead>}
                    <TableHead className="text-right">Amount</TableHead>
                    {showActions && (
                        <TableHead className="w-10">
                            <span className="sr-only">Actions</span>
                        </TableHead>
                    )}
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
                                {row.note && (
                                    <span className="block text-xs text-muted-foreground">
                                        Ref {row.note}
                                    </span>
                                )}
                            </TableCell>
                            {showUnit && <TableCell>{row.unit}</TableCell>}
                            <TableCell className="text-right">
                                <MoneyText
                                    amount={row.amount}
                                    currency={currency}
                                />
                            </TableCell>
                            {showActions && (
                                <TableCell>
                                    {row.actions.length > 0 && (
                                        <DropdownMenu>
                                            <DropdownMenuTrigger
                                                render={
                                                    <Button
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        aria-label={`Actions for ${row.description}`}
                                                    />
                                                }
                                            >
                                                <MoreHorizontal />
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                {row.actions.includes(
                                                    'refund',
                                                ) && (
                                                    <DropdownMenuItem
                                                        onClick={() =>
                                                            onAction(
                                                                row,
                                                                'refund',
                                                            )
                                                        }
                                                    >
                                                        <RotateCcw />
                                                        Refund
                                                    </DropdownMenuItem>
                                                )}
                                                {row.actions.includes(
                                                    'transfer',
                                                ) && (
                                                    <DropdownMenuItem
                                                        onClick={() =>
                                                            onAction(
                                                                row,
                                                                'transfer',
                                                            )
                                                        }
                                                    >
                                                        <ArrowRightLeft />
                                                        Move to another folio
                                                    </DropdownMenuItem>
                                                )}
                                                {row.actions.includes(
                                                    'remove_discount',
                                                ) && (
                                                    <DropdownMenuItem
                                                        variant="destructive"
                                                        onClick={() =>
                                                            onAction(
                                                                row,
                                                                'remove_discount',
                                                            )
                                                        }
                                                    >
                                                        <X />
                                                        Remove discount
                                                    </DropdownMenuItem>
                                                )}
                                                {row.actions.includes(
                                                    'reverse',
                                                ) && (
                                                    <DropdownMenuItem
                                                        variant="destructive"
                                                        onClick={() =>
                                                            onAction(
                                                                row,
                                                                'reverse',
                                                            )
                                                        }
                                                    >
                                                        <Undo2 />
                                                        Reverse
                                                    </DropdownMenuItem>
                                                )}
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    )}
                                </TableCell>
                            )}
                        </TableRow>
                    </Fragment>
                ))}
            </TableBody>
        </Table>
    );
}
