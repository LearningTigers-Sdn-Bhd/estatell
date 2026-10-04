import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    CircleCheck,
    Clock,
    ExternalLink,
    Plus,
    Receipt,
} from 'lucide-react';
import { Fragment, useState } from 'react';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { MoneyText } from '@/components/pms/money-text';
import { AddAddOnDialog } from '@/components/pms/reservations/add-add-on-dialog';
import { RemoveAddOnDialog } from '@/components/pms/reservations/remove-add-on-dialog';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
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
import { folio as folioTab } from '@/routes/reservations';

type AddOn = App.Data.Reservations.ReservationAddOnData;
type Unit = App.Data.Reservations.ReservationUnitOptionData;

const OPEN = ['confirmed', 'checked_in'];

function BillingIcon({
    label,
    className,
    children,
}: {
    label: React.ReactNode;
    className?: string;
    children: React.ReactNode;
}) {
    return (
        <Tooltip>
            <TooltipTrigger
                render={
                    <span
                        role="img"
                        aria-label={
                            typeof label === 'string' ? label : undefined
                        }
                        tabIndex={0}
                        className={cn('inline-flex items-center', className)}
                    />
                }
            >
                {children}
            </TooltipTrigger>
            <TooltipContent>{label}</TooltipContent>
        </Tooltip>
    );
}

/**
 * Where an add-on is billed. A posted add-on shows a tick, the folio and a link to it. One that is not posted shows a
 * clock, and an arrow when routing sends it to another folio than the unit's own.
 */
function AddOnBillingCell({
    reservationId,
    row,
}: {
    reservationId: number;
    row: AddOn;
}) {
    if (row.posted) {
        return (
            <span className="flex items-center gap-2">
                <BillingIcon
                    className="text-success"
                    label={
                        <span>
                            Posted
                            {row.folioLabel
                                ? ` to ${row.folioLabel}`
                                : ''} on{' '}
                            <DateText date={row.postedOn} />
                        </span>
                    }
                >
                    <CircleCheck className="size-4" />
                </BillingIcon>
                {row.folioId !== null && (
                    <Link
                        href={folioTab.url(
                            { reservation: reservationId },
                            { query: { folio: row.folioId } },
                        )}
                        aria-label={`Open the folio line for ${row.chargeItem}`}
                        className={cn(
                            buttonVariants({
                                variant: 'ghost',
                                size: 'icon-sm',
                            }),
                        )}
                    >
                        <ExternalLink />
                    </Link>
                )}
            </span>
        );
    }

    return (
        <span className="flex items-center gap-2 text-muted-foreground">
            <BillingIcon
                label={
                    row.serviceDate ? (
                        <span>
                            Posts in the night audit of{' '}
                            <DateText date={row.serviceDate} />
                        </span>
                    ) : (
                        'Posts at check-in'
                    )
                }
            >
                <Clock className="size-4" />
            </BillingIcon>
            {row.routedTo && (
                <BillingIcon label={`Posts to ${row.routedTo}`}>
                    <ArrowRight className="size-4" />
                </BillingIcon>
            )}
        </span>
    );
}

/**
 * The Add-ons tab: flat rows grouped by unit. A nightly add-on has one row for each night.
 */
export function AddOnsTab({
    reservationId,
    addOns,
    units,
    chargeItems,
    canManage,
    currency,
}: {
    reservationId: number;
    addOns: AddOn[];
    units: Unit[];
    chargeItems?: App.Data.Reservations.ChargeItemOptionData[];
    canManage: boolean;
    currency: App.Enums.Currency | null;
}) {
    const [adding, setAdding] = useState(false);
    const openUnits = units.filter((unit) => OPEN.includes(unit.status.value));
    const openIds = new Set(openUnits.map((unit) => unit.id));
    const canAdd = canManage && openUnits.length > 0;
    const groups = Map.groupBy(addOns, (addOn) => addOn.unitId);

    const columns = canManage ? 7 : 6;
    const totalCents = addOns.reduce(
        (sum, addOn) => sum + Math.round(Number(addOn.total) * 100),
        0,
    );

    return (
        <div className="flex flex-col gap-4">
            <div className="flex items-center justify-between gap-3 ps-3 pt-2">
                <h2 className="font-heading text-base font-medium">Add-ons</h2>
                {canAdd && (
                    <Button variant="outline" onClick={() => setAdding(true)}>
                        <Plus data-icon="inline-start" />
                        Add add-on
                    </Button>
                )}
            </div>
            <div className="overflow-hidden rounded-xl border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Charge item</TableHead>
                            <TableHead className="text-right">
                                Quantity
                            </TableHead>
                            <TableHead>Service date</TableHead>
                            <TableHead className="text-right">
                                Unit amount
                            </TableHead>
                            <TableHead className="text-right">Total</TableHead>
                            <TableHead>Billing</TableHead>
                            {canManage && (
                                <TableHead className="w-12">
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            )}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {addOns.length === 0 && (
                            <TableRow className="hover:bg-transparent">
                                <TableCell colSpan={columns}>
                                    <EmptyState
                                        icon={Receipt}
                                        title="No add-ons"
                                        description="Extras sold with a unit appear here."
                                    />
                                </TableCell>
                            </TableRow>
                        )}
                        {[...groups.values()].map((rows) => (
                            <Fragment key={rows[0].unitId}>
                                <TableRow className="bg-muted/50 hover:bg-muted/50">
                                    <TableCell
                                        colSpan={columns}
                                        className="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                                    >
                                        {rows[0].unitLabel}
                                    </TableCell>
                                </TableRow>
                                {rows.map((row) => (
                                    <TableRow key={row.id}>
                                        <TableCell>{row.chargeItem}</TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {row.quantity}
                                        </TableCell>
                                        <TableCell>
                                            <DateText date={row.serviceDate} />
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <MoneyText
                                                amount={row.unitAmount}
                                                currency={currency}
                                            />
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <MoneyText
                                                amount={row.total}
                                                currency={currency}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <AddOnBillingCell
                                                reservationId={reservationId}
                                                row={row}
                                            />
                                        </TableCell>
                                        {canManage && (
                                            <TableCell className="text-right">
                                                {!row.posted &&
                                                    openIds.has(row.unitId) && (
                                                        <RemoveAddOnDialog
                                                            reservationId={
                                                                reservationId
                                                            }
                                                            addOn={row}
                                                        />
                                                    )}
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                            </Fragment>
                        ))}
                    </TableBody>
                    {addOns.length > 0 && (
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={4}>Total</TableCell>
                                <TableCell className="text-right">
                                    <MoneyText
                                        amount={(totalCents / 100).toFixed(2)}
                                        currency={currency}
                                    />
                                </TableCell>
                                <TableCell />
                                {canManage && <TableCell />}
                            </TableRow>
                        </TableFooter>
                    )}
                </Table>
            </div>
            <AddAddOnDialog
                open={adding}
                onOpenChange={setAdding}
                reservationId={reservationId}
                units={openUnits}
                chargeItems={chargeItems}
                currency={currency}
            />
        </div>
    );
}
