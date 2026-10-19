import {
    ArrowRightLeft,
    Ban,
    ChevronDown,
    CircleMinus,
    CirclePlus,
    Clock,
    FileText,
    Printer,
    MoreHorizontal,
    PencilLine,
    RefreshCw,
    RotateCcw,
    Scissors,
    Tag,
    Undo2,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Fragment, useState } from 'react';
import { DateText } from '@/components/pms/date-text';
import { MoneyText } from '@/components/pms/money-text';
import { Button } from '@/components/ui/button';
import { useCanCorrectMoney } from '@/hooks/use-is-impersonating';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
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
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { receipt } from '@/routes/reservations/folios/payments';

type Row = App.Data.Folio.FolioRowData;

const TAG_ICONS: Record<App.Enums.FolioRowTag, LucideIcon> = {
    scheduled: Clock,
    overridden: PencilLine,
    reversed: Ban,
    reversal: Undo2,
    refund: RotateCcw,
    manual: PencilLine,
};

const TAG_TONES: Record<App.Enums.Tone, string> = {
    neutral: 'text-muted-foreground',
    info: 'text-info',
    success: 'text-success',
    warning: 'text-warning',
    danger: 'text-destructive',
};

const ROW_TINTS: Partial<Record<App.Enums.Tone, string>> = {
    info: 'bg-info/5 hover:bg-info/10',
    success: 'bg-success/5 hover:bg-success/10',
    warning: 'bg-warning/5 hover:bg-warning/10',
    danger: 'bg-destructive/5 hover:bg-destructive/10',
};

/** The row takes the tone of its first marker that has one. Neutral markers leave the row plain. */
function rowTint(row: Row): string | undefined {
    const tag = row.tags.find((one) => ROW_TINTS[one.tone] !== undefined);

    return tag === undefined ? undefined : ROW_TINTS[tag.tone];
}

/**
 * A row marker as an icon. The label is the tooltip and the accessible name, so the meaning never rests on color. A
 * Scheduled marker shows on top-level rows only: its discount and tax rows sit under it and the section header already
 * says Scheduled.
 */
function RowTag({ tag, row }: { tag: App.Data.Shared.BadgeData; row: Row }) {
    const Icon = TAG_ICONS[tag.value as App.Enums.FolioRowTag];

    if (Icon === undefined || (tag.value === 'scheduled' && row.level > 0)) {
        return null;
    }

    return (
        <Tooltip>
            <TooltipTrigger
                render={
                    <span
                        role="img"
                        aria-label={tag.label}
                        tabIndex={0}
                        className={cn(
                            'inline-flex shrink-0 items-center',
                            TAG_TONES[tag.tone],
                        )}
                    />
                }
            >
                <Icon className="size-4" />
            </TooltipTrigger>
            <TooltipContent>{tag.label}</TooltipContent>
        </Tooltip>
    );
}

const SECTIONS: Record<Row['state'], string> = {
    posted: 'Posted',
    scheduled: 'Scheduled',
};

/**
 * The Posted rows, then the Scheduled rows, as the server sent them. Scheduled, Reversed and Reversal rows are muted,
 * the Scheduled header collapses its rows (open by default), a Reversed row is struck through, a row takes the tone of its marker as a light tint, and children (a discount, then its tax) are indented. Row markers are icons. A payment shows its reference under the method. The Unit column shows only
 * when a row names a unit.
 */
export function FolioRowsTable({
    reservationId,
    folioId,
    rows,
    currency,
    onAction,
    onPrintVoucher,
}: {
    reservationId: number;
    folioId: number;
    rows: Row[];
    currency: App.Data.Property.CurrencyData | null;
    onPrintVoucher: (row: Row) => void;
    onAction: (
        row: Row,
        action:
            | 'reverse'
            | 'move'
            | 'remove_discount'
            | 'refund'
            | 'correct_price'
            | 'split'
            | 'remove_tax'
            | 'add_tax'
            | 'recalculate_tax',
    ) => void;
}) {
    const canCorrectMoney = useCanCorrectMoney();
    const [scheduledOpen, setScheduledOpen] = useState(true);
    const scheduledCount = rows.filter(
        (row) => row.state === 'scheduled',
    ).length;
    const showUnit = rows.some((row) => row.unit !== null);
    const hasMenu = (row: Row) =>
        row.actions.length > 0 || row.hasReceipt || row.printable;
    const showActions = rows.some(hasMenu);
    const columns = 3 + (showUnit ? 1 : 0) + (showActions ? 1 : 0);

    return (
        <div className="overflow-hidden rounded-xl border">
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
                                        {row.state === 'scheduled' ? (
                                            <button
                                                type="button"
                                                aria-expanded={scheduledOpen}
                                                onClick={() =>
                                                    setScheduledOpen(
                                                        (open) => !open,
                                                    )
                                                }
                                                className="flex w-full items-center gap-2 uppercase"
                                            >
                                                <ChevronDown
                                                    aria-hidden
                                                    className={cn(
                                                        'size-4 transition-transform motion-reduce:transition-none',
                                                        !scheduledOpen &&
                                                            '-rotate-90',
                                                    )}
                                                />
                                                {SECTIONS.scheduled}
                                                {!scheduledOpen && (
                                                    <span className="font-normal normal-case">
                                                        {scheduledCount} rows
                                                        hidden
                                                    </span>
                                                )}
                                            </button>
                                        ) : (
                                            SECTIONS[row.state as Row['state']]
                                        )}
                                    </TableCell>
                                </TableRow>
                            )}
                            {row.state === 'scheduled' &&
                            !scheduledOpen ? null : (
                                <TableRow
                                    className={cn(
                                        (row.state === 'scheduled' ||
                                            row.tags.some(
                                                (tag) =>
                                                    tag.value === 'reversed' ||
                                                    tag.value === 'reversal',
                                            )) &&
                                            'text-muted-foreground',
                                        row.tags.some(
                                            (tag) => tag.value === 'reversed',
                                        ) && 'line-through',
                                        rowTint(row),
                                    )}
                                >
                                    <TableCell>
                                        {row.level === 0 && (
                                            <DateText date={row.date} />
                                        )}
                                        {row.level === 0 &&
                                            row.postedDate !== null && (
                                                <div className="text-xs text-muted-foreground">
                                                    posted{' '}
                                                    <DateText
                                                        date={row.postedDate}
                                                    />
                                                </div>
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
                                                <RowTag
                                                    key={tag.value}
                                                    tag={tag}
                                                    row={row}
                                                />
                                            ))}
                                        </span>
                                        {row.note && (
                                            <span className="block text-xs text-muted-foreground">
                                                Ref {row.note}
                                            </span>
                                        )}
                                    </TableCell>
                                    {showUnit && (
                                        <TableCell>{row.unit}</TableCell>
                                    )}
                                    <TableCell className="text-right">
                                        <MoneyText
                                            amount={row.amount}
                                            currency={currency}
                                        />
                                    </TableCell>
                                    {showActions && (
                                        <TableCell>
                                            {hasMenu(row) && (
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
                                                    <DropdownMenuContent
                                                        align="end"
                                                        className="min-w-52"
                                                    >
                                                        {row.hasReceipt &&
                                                            row.paymentId !==
                                                                null && (
                                                                <DropdownMenuItem
                                                                    render={
                                                                        <a
                                                                            href={receipt.url(
                                                                                {
                                                                                    reservation:
                                                                                        reservationId,
                                                                                    folio: folioId,
                                                                                    payment:
                                                                                        row.paymentId,
                                                                                },
                                                                            )}
                                                                            target="_blank"
                                                                            rel="noopener"
                                                                        />
                                                                    }
                                                                >
                                                                    <FileText />
                                                                    Download
                                                                    receipt
                                                                </DropdownMenuItem>
                                                            )}
                                                        {row.printable && (
                                                            <DropdownMenuItem
                                                                onClick={() =>
                                                                    onPrintVoucher(
                                                                        row,
                                                                    )
                                                                }
                                                            >
                                                                <Printer />
                                                                Print voucher
                                                            </DropdownMenuItem>
                                                        )}
                                                        {canCorrectMoney &&
                                                            row.actions.includes(
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
                                                            'correct_price',
                                                        ) && (
                                                            <DropdownMenuItem
                                                                onClick={() =>
                                                                    onAction(
                                                                        row,
                                                                        'correct_price',
                                                                    )
                                                                }
                                                            >
                                                                <Tag />
                                                                Correct price…
                                                            </DropdownMenuItem>
                                                        )}
                                                        {row.actions.includes(
                                                            'move',
                                                        ) && (
                                                            <DropdownMenuItem
                                                                onClick={() =>
                                                                    onAction(
                                                                        row,
                                                                        'move',
                                                                    )
                                                                }
                                                            >
                                                                <ArrowRightLeft />
                                                                Move…
                                                            </DropdownMenuItem>
                                                        )}
                                                        {row.actions.includes(
                                                            'split',
                                                        ) && (
                                                            <DropdownMenuItem
                                                                onClick={() =>
                                                                    onAction(
                                                                        row,
                                                                        'split',
                                                                    )
                                                                }
                                                            >
                                                                <Scissors />
                                                                Split…
                                                            </DropdownMenuItem>
                                                        )}
                                                        {row.actions.includes(
                                                            'add_tax',
                                                        ) && (
                                                            <>
                                                                <DropdownMenuSeparator />
                                                                {row.actions.includes(
                                                                    'remove_tax',
                                                                ) && (
                                                                    <DropdownMenuItem
                                                                        onClick={() =>
                                                                            onAction(
                                                                                row,
                                                                                'remove_tax',
                                                                            )
                                                                        }
                                                                    >
                                                                        <CircleMinus />
                                                                        Remove
                                                                        tax…
                                                                    </DropdownMenuItem>
                                                                )}
                                                                <DropdownMenuItem
                                                                    onClick={() =>
                                                                        onAction(
                                                                            row,
                                                                            'add_tax',
                                                                        )
                                                                    }
                                                                >
                                                                    <CirclePlus />
                                                                    Add tax…
                                                                </DropdownMenuItem>
                                                                <DropdownMenuItem
                                                                    onClick={() =>
                                                                        onAction(
                                                                            row,
                                                                            'recalculate_tax',
                                                                        )
                                                                    }
                                                                >
                                                                    <RefreshCw />
                                                                    Recalculate
                                                                    tax
                                                                </DropdownMenuItem>
                                                                <DropdownMenuSeparator />
                                                            </>
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
                            )}
                        </Fragment>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}
