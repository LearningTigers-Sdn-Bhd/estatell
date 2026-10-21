import { ChevronDown, ChevronRight } from 'lucide-react';
import { Fragment, useState } from 'react';
import { MoneyInput } from '@/components/pms/money-input';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    columnValue,
    pairKey,
    savedPrice,
    setColumnValue,
} from './bulk-price-model';
import type { BulkColumn, PairGrid, SavedPrices } from './bulk-price-model';

type Plan = App.Data.Rates.RateBulkPlanData;
type RoomType = App.Data.Rates.RateBulkRoomTypeData;

function planNote(plan: Plan): string | null {
    if (plan.derived) {
        return `Follows ${plan.followsName}. Restrictions only.`;
    }

    if (!plan.hasOwnPrices) {
        return 'Set on the package. Restrictions only.';
    }

    if (plan.pricingBasis === 'per_guest') {
        return 'Price per adult';
    }

    if (plan.pricingBasis === 'per_bed') {
        return 'Price per bed';
    }

    return null;
}

/** A saved price as the placeholder of an empty cell, so a blank cell shows what it keeps. */
function placeholder(saved: string | null): string | undefined {
    return saved === null ? undefined : Number(saved).toFixed(2);
}

/**
 * The prices of the picked plans for the picked room types: a heading for each room type, then a row for each plan
 * that sells it. An empty cell keeps the saved price and shows it as the placeholder. A row with prices by adults
 * opens its adult-count lines under it.
 */
export function BulkPriceTable({
    grids,
    plans,
    roomTypes,
    columns,
    saved,
    currency,
    onChange,
    onToggleByAdults,
}: {
    grids: PairGrid[];
    plans: Plan[];
    roomTypes: RoomType[];
    columns: BulkColumn[];
    saved: SavedPrices;
    currency: App.Data.Property.CurrencyData;
    onChange: (next: PairGrid) => void;
    onToggleByAdults: (grid: PairGrid, byAdults: boolean) => void;
}) {
    const [openGuestRows, setOpenGuestRows] = useState<string[]>([]);
    const compact = columns.length > 2;
    const planById = new Map(plans.map((plan) => [plan.id, plan]));

    const isOpen = (grid: PairGrid, plan: Plan) =>
        plan.pricingBasis === 'per_guest'
            ? openGuestRows.includes(pairKey(grid.ratePlanId, grid.roomTypeId))
            : grid.byAdults;

    const toggle = (grid: PairGrid, plan: Plan) => {
        if (plan.pricingBasis === 'per_guest') {
            const key = pairKey(grid.ratePlanId, grid.roomTypeId);

            setOpenGuestRows((current) =>
                current.includes(key)
                    ? current.filter((item) => item !== key)
                    : [...current, key],
            );

            return;
        }

        onToggleByAdults(grid, !grid.byAdults);
    };

    const priceInputs = (grid: PairGrid, row: GridRow, label: string) =>
        columns.map((column) => (
            <TableCell key={column.id}>
                <MoneyInput
                    value={columnValue(row, column)}
                    currency={compact ? null : currency}
                    placeholder={
                        row.kind === 'amount'
                            ? placeholder(savedPrice(saved, grid, column))
                            : undefined
                    }
                    onChange={(value) =>
                        onChange({
                            ...grid,
                            rows: grid.rows.map((item) =>
                                item.id === row.id
                                    ? setColumnValue(item, column, value)
                                    : item,
                            ),
                        })
                    }
                    disabled={!grid.priceable}
                    aria-label={`${label}, ${column.label}`}
                />
            </TableCell>
        ));

    return (
        <div className="overflow-auto rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead className="min-w-56">
                            Room type and plan
                        </TableHead>
                        {columns.map((column) => (
                            <TableHead
                                key={column.id}
                                className={compact ? 'min-w-28' : 'min-w-44'}
                            >
                                {column.label}
                                {compact && (
                                    <span className="ml-1 font-normal text-muted-foreground">
                                        {currency.code}
                                    </span>
                                )}
                            </TableHead>
                        ))}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {roomTypes.map((roomType) => {
                        const rows = grids.filter(
                            (grid) => grid.roomTypeId === roomType.id,
                        );

                        if (rows.length === 0) {
                            return null;
                        }

                        return (
                            <Fragment key={roomType.id}>
                                <TableRow className="bg-muted/50 hover:bg-muted/50">
                                    <TableCell
                                        colSpan={columns.length + 1}
                                        className="font-medium"
                                    >
                                        {roomType.name}
                                    </TableCell>
                                </TableRow>
                                {rows.map((grid) => {
                                    const plan = planById.get(grid.ratePlanId)!;
                                    const note = planNote(plan);
                                    const amountRow = grid.rows.find(
                                        (row) => row.kind === 'amount',
                                    );
                                    const extraRows = grid.rows.filter(
                                        (row) => row.kind === 'occupancy',
                                    );
                                    const canByAdults =
                                        grid.priceable &&
                                        roomType.maxAdults > 1 &&
                                        plan.pricingBasis !== 'per_bed';
                                    const open =
                                        canByAdults && isOpen(grid, plan);

                                    return (
                                        <Fragment
                                            key={pairKey(
                                                grid.ratePlanId,
                                                grid.roomTypeId,
                                            )}
                                        >
                                            <TableRow>
                                                <TableCell className="pl-6">
                                                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                                                        <span className="flex flex-col">
                                                            <span>
                                                                {plan.name}
                                                            </span>
                                                            {note && (
                                                                <span className="text-xs text-muted-foreground">
                                                                    {note}
                                                                </span>
                                                            )}
                                                        </span>
                                                        {canByAdults && (
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="sm"
                                                                aria-expanded={
                                                                    open
                                                                }
                                                                onClick={() =>
                                                                    toggle(
                                                                        grid,
                                                                        plan,
                                                                    )
                                                                }
                                                            >
                                                                {open ? (
                                                                    <ChevronDown data-icon="inline-start" />
                                                                ) : (
                                                                    <ChevronRight data-icon="inline-start" />
                                                                )}
                                                                By adults
                                                            </Button>
                                                        )}
                                                    </div>
                                                </TableCell>
                                                {amountRow ? (
                                                    priceInputs(
                                                        grid,
                                                        amountRow,
                                                        `${roomType.name}, ${plan.name}`,
                                                    )
                                                ) : (
                                                    <TableCell
                                                        colSpan={columns.length}
                                                        className="text-sm text-muted-foreground"
                                                    >
                                                        No price here.
                                                    </TableCell>
                                                )}
                                            </TableRow>
                                            {open &&
                                                extraRows.map((row) => (
                                                    <TableRow
                                                        key={row.id}
                                                        className="bg-muted/20"
                                                    >
                                                        <TableCell className="pl-12 text-sm text-muted-foreground">
                                                            {row.label}
                                                        </TableCell>
                                                        {priceInputs(
                                                            grid,
                                                            row,
                                                            `${roomType.name}, ${plan.name}, ${row.label}`,
                                                        )}
                                                    </TableRow>
                                                ))}
                                        </Fragment>
                                    );
                                })}
                            </Fragment>
                        );
                    })}
                </TableBody>
            </Table>
        </div>
    );
}

type GridRow = PairGrid['rows'][number];
