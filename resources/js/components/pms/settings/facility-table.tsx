import { Link } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Tent } from 'lucide-react';
import { EmptyState } from '@/components/pms/empty-state';
import { MoneyText } from '@/components/pms/money-text';
import { Switch } from '@/components/ui/switch';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { show } from '@/routes/settings/facilities';

type Facility = App.Data.Facilities.FacilityData;
/**
 * The Facilities settings list in its saved order. Each row has Move up, Move down and Edit, like Charge items. Facilities that are off stay in place and are muted. With no
 * facilities the table keeps its header and shows the empty state in one row.
 */
export function FacilityTable({
    facilities,
    currency,
    busyIds,
    onMove,
    onToggleActive,
}: {
    facilities: Facility[];
    currency: App.Data.Property.CurrencyData | null;
    busyIds: Set<number>;
    onMove: (from: number, to: number) => void;
    onToggleActive: (facility: Facility, active: boolean) => void;
}) {
    const activeFacilities = facilities.filter((facility) => facility.active);

    return (
        <div className="overflow-auto rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead className="w-28">Available</TableHead>
                        <TableHead>Name</TableHead>
                        <TableHead>Booked by</TableHead>
                        <TableHead className="text-right">How many</TableHead>
                        <TableHead className="text-right">
                            Rate{currency ? ` (${currency.code})` : ''}
                        </TableHead>
                        <TableHead>
                            <span className="sr-only">Actions</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {facilities.length === 0 && (
                        <TableRow className="hover:bg-transparent">
                            <TableCell colSpan={6}>
                                <EmptyState
                                    icon={Tent}
                                    title="No facilities yet."
                                    description="Add the halls, equipment and activities guests can book."
                                />
                            </TableCell>
                        </TableRow>
                    )}
                    {facilities.map((facility) => {
                        const position = activeFacilities.indexOf(facility);

                        return (
                            <TableRow
                                key={facility.id}
                                className={
                                    facility.active
                                        ? undefined
                                        : 'text-muted-foreground'
                                }
                            >
                                <TableCell>
                                    <Switch
                                        size="sm"
                                        checked={facility.active}
                                        disabled={busyIds.has(facility.id)}
                                        aria-label={`Make ${facility.name} ${facility.active ? 'unavailable' : 'available'}`}
                                        onCheckedChange={(active) =>
                                            onToggleActive(facility, active)
                                        }
                                    />
                                </TableCell>
                                <TableCell className="font-medium">
                                    <Link
                                        href={show.url({
                                            facility: facility.id,
                                        })}
                                        className="underline-offset-4 hover:underline"
                                    >
                                        {facility.name}
                                    </Link>
                                </TableCell>
                                <TableCell>{facility.bookedByLabel}</TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {facility.capacity} {facility.unitLabel}
                                </TableCell>
                                <TableCell className="text-right">
                                    {facility.amount === null ? (
                                        <span className="text-muted-foreground">
                                            Charge by hand
                                        </span>
                                    ) : (
                                        <>
                                            <MoneyText
                                                amount={facility.amount}
                                                currency={currency}
                                                plain
                                            />{' '}
                                            <span className="text-muted-foreground">
                                                {facility.rateBasisLabel.toLowerCase()}
                                            </span>
                                        </>
                                    )}
                                </TableCell>
                                <TableCell>
                                    <div className="flex justify-end gap-1">
                                        {facility.active && (
                                            <>
                                                <Button
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    aria-label={`Move ${facility.name} up`}
                                                    disabled={
                                                        position === 0 ||
                                                        busyIds.has(facility.id)
                                                    }
                                                    onClick={() =>
                                                        onMove(
                                                            position,
                                                            position - 1,
                                                        )
                                                    }
                                                >
                                                    <ArrowUp />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    aria-label={`Move ${facility.name} down`}
                                                    disabled={
                                                        position ===
                                                            activeFacilities.length -
                                                                1 ||
                                                        busyIds.has(facility.id)
                                                    }
                                                    onClick={() =>
                                                        onMove(
                                                            position,
                                                            position + 1,
                                                        )
                                                    }
                                                >
                                                    <ArrowDown />
                                                </Button>
                                            </>
                                        )}
                                        <Link
                                            href={show.url({
                                                facility: facility.id,
                                            })}
                                            className={buttonVariants({
                                                variant: 'ghost',
                                                size: 'sm',
                                            })}
                                        >
                                            Edit
                                        </Link>
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
