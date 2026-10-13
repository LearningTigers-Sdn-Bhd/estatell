import { Link } from '@inertiajs/react';
import { Ellipsis, Ticket } from 'lucide-react';
import { taxLabel } from '@/components/pms/charge-item-taxes-field';
import { EmptyState } from '@/components/pms/empty-state';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Switch } from '@/components/ui/switch';
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
import { edit as editChargeItem } from '@/routes/settings/charge-items';

type Fare = App.Data.Boats.BoatFareRowData;
type Option = { value: string; label: string };

/**
 * The Fares tab's list. Unavailable fares stay in place and are muted. A fare that an available departure uses cannot
 * be turned off, so its switch is disabled with the reason. With no fares the table keeps its header and shows the
 * empty state in one row.
 */
export function BoatFareTable({
    fares,
    money,
    currency,
    chargingUnits,
    togglingIds,
    onToggleActive,
    onEdit,
}: {
    fares: Fare[];
    money: Intl.NumberFormat;
    currency: App.Enums.Currency;
    chargingUnits: Option[];
    togglingIds: Set<number>;
    onToggleActive: (fare: Fare, active: boolean) => void;
    onEdit: (fare: Fare) => void;
}) {
    return (
        <div className="overflow-auto rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Available</TableHead>
                        <TableHead>Name</TableHead>
                        <TableHead className="text-right">Adult</TableHead>
                        <TableHead>Children</TableHead>
                        <TableHead>Taxes</TableHead>
                        <TableHead>Used by</TableHead>
                        <TableHead>
                            <span className="sr-only">Actions</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {fares.length === 0 && (
                        <TableRow className="hover:bg-transparent">
                            <TableCell colSpan={7}>
                                <EmptyState
                                    icon={Ticket}
                                    title="No fares yet."
                                    description="A fare is the one-way price per guest for a boat."
                                />
                            </TableCell>
                        </TableRow>
                    )}
                    {fares.map((fare) => {
                        const inUse = fare.active && fare.usedBy > 0;
                        const unit = chargingUnits.find(
                            (option) => option.value === fare.chargingUnit,
                        );
                        const toggle = (
                            <Switch
                                size="sm"
                                checked={fare.active}
                                disabled={togglingIds.has(fare.id) || inUse}
                                aria-label={`Make ${fare.name} ${fare.active ? 'unavailable' : 'available'}`}
                                onCheckedChange={(active) =>
                                    onToggleActive(fare, active)
                                }
                            />
                        );

                        return (
                            <TableRow
                                key={fare.id}
                                className={
                                    fare.active
                                        ? undefined
                                        : 'text-muted-foreground'
                                }
                            >
                                <TableCell>
                                    {inUse ? (
                                        <Tooltip>
                                            <TooltipTrigger
                                                render={
                                                    <span className="inline-flex" />
                                                }
                                            >
                                                {toggle}
                                            </TooltipTrigger>
                                            <TooltipContent>
                                                {`Used by ${fare.usedBy} available ${fare.usedBy === 1 ? 'departure' : 'departures'}. Change their fare first.`}
                                            </TooltipContent>
                                        </Tooltip>
                                    ) : (
                                        toggle
                                    )}
                                </TableCell>
                                <TableCell>
                                    <span className="font-medium">
                                        {fare.name}
                                    </span>
                                    {fare.chargingUnit !== 'per_guest' &&
                                        unit && (
                                            <span className="block text-xs text-muted-foreground">
                                                {unit.label}
                                            </span>
                                        )}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {fare.amount === null
                                        ? '—'
                                        : money.format(Number(fare.amount))}
                                </TableCell>
                                <TableCell>{fare.childrenSummary}</TableCell>
                                <TableCell>
                                    {fare.taxes.length === 0
                                        ? 'None'
                                        : fare.taxes
                                              .map((tax) =>
                                                  taxLabel(tax, currency),
                                              )
                                              .join(', ')}
                                </TableCell>
                                <TableCell>
                                    {fare.usedBy === 0 ? (
                                        <span className="text-muted-foreground">
                                            —
                                        </span>
                                    ) : fare.usedBy === 1 ? (
                                        '1 departure'
                                    ) : (
                                        `${fare.usedBy} departures`
                                    )}
                                </TableCell>
                                <TableCell>
                                    <div className="flex justify-end">
                                        <DropdownMenu>
                                            <DropdownMenuTrigger
                                                render={
                                                    <Button
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        aria-label={`Actions for ${fare.name}`}
                                                    />
                                                }
                                            >
                                                <Ellipsis />
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                <DropdownMenuItem
                                                    onClick={() => onEdit(fare)}
                                                >
                                                    Edit
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    render={
                                                        <Link
                                                            href={editChargeItem(
                                                                {
                                                                    chargeItem:
                                                                        fare.id,
                                                                },
                                                            )}
                                                        />
                                                    }
                                                >
                                                    More settings
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </div>
                                </TableCell>
                            </TableRow>
                        );
                    })}
                </TableBody>
            </Table>
        </div>
    );
}
