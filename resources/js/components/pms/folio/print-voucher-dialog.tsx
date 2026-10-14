import {
    BadgePercent,
    Eraser,
    Landmark,
    Printer,
    Receipt,
    WandSparkles,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Fragment, useState } from 'react';
import { MoneyText } from '@/components/pms/money-text';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { DateText } from '@/components/pms/date-text';
import { voucher } from '@/routes/reservations/folios';
import { voucher as unitVoucher } from '@/routes/reservations/units';

type Row = App.Data.Folio.FolioRowData;

const KIND_ICONS: Record<string, LucideIcon> = {
    charge: Receipt,
    adjustment: BadgePercent,
    tax: Landmark,
};

export type VoucherOptions = {
    kinds: { value: string; label: string }[];
    maxLines: number;
};

function VoucherForm({
    reservationId,
    folioId,
    unitId,
    rows,
    options,
    initial,
    currency,
}: {
    reservationId: number;
    folioId: number | null;
    unitId: number | null;
    rows: Row[];
    options: VoucherOptions;
    initial: string[];
    currency: App.Data.Property.CurrencyData | null;
}) {
    const [selected, setSelected] = useState<Set<string>>(new Set(initial));
    const ids = rows.map((row) => row.key);
    const chosen = rows.filter((row) => selected.has(row.key));
    const totalCents = chosen.reduce(
        (sum, row) => sum + Math.round(Number(row.amount) * 100),
        0,
    );
    const tooMany = chosen.length > options.maxLines;

    const change = (lineIds: string[], on: boolean) =>
        setSelected((current) => {
            const next = new Set(current);

            lineIds.forEach((id) => (on ? next.add(id) : next.delete(id)));

            return next;
        });

    const kindIds = (kind: string) =>
        rows.filter((row) => row.kind === kind).map((row) => row.key);

    const print = () => {
        const lines = chosen
            .filter((row) => row.state === 'posted')
            .map((row) => row.lineId as number);
        const scheduled = chosen
            .filter((row) => row.state === 'scheduled')
            .map((row) => row.key);
        const url =
            folioId === null
                ? unitVoucher.url(
                      {
                          reservation: reservationId,
                          unit: unitId as number,
                      },
                      { query: { scheduled } },
                  )
                : voucher.url(
                      { reservation: reservationId, folio: folioId },
                      { query: { lines, scheduled } },
                  );

        window.open(url, '_blank', 'noopener');
    };

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center gap-2">
                <span className="flex items-center gap-1.5 text-sm text-muted-foreground">
                    <WandSparkles aria-hidden className="size-4" />
                    Quick select
                </span>
                {options.kinds.map((kind) => {
                    const own = kindIds(kind.value);
                    const all =
                        own.length > 0 && own.every((id) => selected.has(id));

                    const Icon = KIND_ICONS[kind.value];

                    return (
                        <Button
                            key={kind.value}
                            type="button"
                            size="sm"
                            variant={all ? 'secondary' : 'outline'}
                            aria-pressed={all}
                            disabled={own.length === 0}
                            onClick={() => change(own, !all)}
                        >
                            {Icon && <Icon data-icon="inline-start" />}
                            {kind.label}
                        </Button>
                    );
                })}
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={() => setSelected(new Set())}
                    disabled={selected.size === 0}
                >
                    <Eraser data-icon="inline-start" />
                    Clear
                </Button>
            </div>
            <div className="max-h-96 overflow-y-auto rounded-xl border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="w-10">
                                <Checkbox
                                    aria-label="Select all lines"
                                    checked={
                                        ids.length > 0 &&
                                        ids.every((id) => selected.has(id))
                                    }
                                    indeterminate={
                                        selected.size > 0 &&
                                        !ids.every((id) => selected.has(id))
                                    }
                                    onCheckedChange={(on) => change(ids, on)}
                                />
                            </TableHead>
                            <TableHead>Date</TableHead>
                            <TableHead>Description</TableHead>
                            <TableHead className="text-right">Amount</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((row, index) => (
                            <Fragment key={row.key}>
                                {row.state !== rows[index - 1]?.state &&
                                    (index > 0 ||
                                        row.state === 'scheduled') && (
                                        <TableRow className="bg-muted/50 hover:bg-muted/50">
                                            <TableCell
                                                colSpan={4}
                                                className="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                                            >
                                                {row.state === 'scheduled'
                                                    ? 'Scheduled, not posted yet'
                                                    : 'Posted'}
                                            </TableCell>
                                        </TableRow>
                                    )}
                                <TableRow>
                                    <TableCell>
                                        <Checkbox
                                            aria-label={`Select ${row.description}`}
                                            checked={selected.has(row.key)}
                                            onCheckedChange={(on) =>
                                                change([row.key], on)
                                            }
                                        />
                                    </TableCell>
                                    <TableCell>
                                        {row.level === 0 && (
                                            <DateText date={row.date} />
                                        )}
                                    </TableCell>
                                    <TableCell
                                        className={row.level > 0 ? 'pl-6' : ''}
                                    >
                                        {row.level > 0 && (
                                            <span aria-hidden>↳ </span>
                                        )}
                                        {row.description}
                                    </TableCell>
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
            </div>
            <p className="text-xs text-muted-foreground">
                Reversed lines and payments are not listed. A scheduled row is
                not posted yet, so its amount can still change.
            </p>
            <div className="flex items-center justify-between gap-2 text-sm">
                <span>
                    {chosen.length} {chosen.length === 1 ? 'line' : 'lines'}{' '}
                    selected
                </span>
                <span className="font-medium">
                    Total{' '}
                    <MoneyText
                        amount={(totalCents / 100).toFixed(2)}
                        currency={currency}
                    />
                </span>
            </div>
            {tooMany && (
                <p role="alert" className="text-sm text-destructive">
                    Select up to {options.maxLines} lines.
                </p>
            )}
            <DialogFooter>
                <DialogClose render={<Button variant="outline" />}>
                    Cancel
                </DialogClose>
                <Button
                    type="button"
                    disabled={chosen.length === 0 || tooMany}
                    focusableWhenDisabled
                    onClick={print}
                >
                    <Printer data-icon="inline-start" />
                    Print voucher
                </Button>
            </DialogFooter>
        </div>
    );
}

/**
 * Prints a voucher for the rows the user ticks: posted lines, then Scheduled rows under their own heading. The list
 * holds the open entry's printable rows (the server decides which). The PDF opens in a new tab and the Dialog stays open, so the selection can change and print again.
 */
export function PrintVoucherDialog({
    open,
    onOpenChange,
    reservationId,
    folioId,
    unitId,
    rows,
    options,
    initial,
    currency,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    /** The folio, or null for a unit that has no folio yet. */
    folioId: number | null;
    /** The unit of a "not started" entry. */
    unitId: number | null;
    rows: Row[];
    options: VoucherOptions;
    initial: string[];
    currency: App.Data.Property.CurrencyData | null;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-4xl">
                <DialogHeader>
                    <DialogTitle>Print voucher</DialogTitle>
                    <DialogDescription>
                        Choose the posted lines to print.
                    </DialogDescription>
                </DialogHeader>
                <VoucherForm
                    reservationId={reservationId}
                    folioId={folioId}
                    unitId={unitId}
                    rows={rows}
                    options={options}
                    initial={initial}
                    currency={currency}
                />
            </DialogContent>
        </Dialog>
    );
}
