import { Ellipsis, Ship } from 'lucide-react';
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

type Departure = App.Data.Boats.BoatDepartureData;
type Fare = App.Data.Boats.BoatFareData;

/** The fare's name, with its adult price when it has one. Shared with the departure form. */
export function fareLabel(fare: Fare, money: Intl.NumberFormat): string {
    const name = fare.active ? fare.name : `${fare.name} (unavailable)`;

    return fare.amount === null
        ? name
        : `${name} · ${money.format(Number(fare.amount))}`;
}

/**
 * One boat direction's departures, sorted by the server. Unavailable rows stay in place and are muted. With no
 * departures the table keeps its header and shows the empty state in one row.
 */
export function BoatDepartureTable({
    departures,
    emptyTitle,
    money,
    togglingIds,
    onToggleActive,
    onEdit,
    onDelete,
}: {
    departures: Departure[];
    emptyTitle: string;
    money: Intl.NumberFormat;
    togglingIds: Set<number>;
    onToggleActive: (departure: Departure, active: boolean) => void;
    onEdit: (departure: Departure) => void;
    onDelete: (departure: Departure) => void;
}) {
    return (
        <div className="overflow-auto rounded-lg border">
            <Table className="table-fixed">
                <TableHeader>
                    <TableRow>
                        <TableHead className="w-28">Available</TableHead>
                        <TableHead className="w-24">Leaves</TableHead>
                        <TableHead className="w-24">Arrives</TableHead>
                        <TableHead>Pick-up point</TableHead>
                        <TableHead className="w-20 text-right">Seats</TableHead>
                        <TableHead className="w-72">Fare</TableHead>
                        <TableHead className="w-14">
                            <span className="sr-only">Actions</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {departures.length === 0 && (
                        <TableRow className="hover:bg-transparent">
                            <TableCell colSpan={7}>
                                <EmptyState icon={Ship} title={emptyTitle} />
                            </TableCell>
                        </TableRow>
                    )}
                    {departures.map((departure) => (
                        <TableRow
                            key={departure.id}
                            className={
                                departure.active
                                    ? undefined
                                    : 'text-muted-foreground'
                            }
                        >
                            <TableCell>
                                <Switch
                                    size="sm"
                                    checked={departure.active}
                                    disabled={togglingIds.has(departure.id)}
                                    aria-label={`Make ${departure.leavesAt} ${departure.direction === 'in' ? 'boat in' : 'boat out'} ${departure.active ? 'unavailable' : 'available'}`}
                                    onCheckedChange={(active) =>
                                        onToggleActive(departure, active)
                                    }
                                />
                            </TableCell>
                            <TableCell className="tabular-nums">
                                {departure.leavesAt}
                            </TableCell>
                            <TableCell className="tabular-nums">
                                {departure.arrivesAt}
                            </TableCell>
                            <TableCell>
                                {departure.pickupPoint ?? (
                                    <span className="text-muted-foreground">
                                        —
                                    </span>
                                )}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {departure.seats ?? (
                                    <span className="text-muted-foreground">
                                        —
                                    </span>
                                )}
                            </TableCell>
                            <TableCell>
                                {departure.fare === null ? (
                                    <span className="text-muted-foreground">
                                        No fare
                                    </span>
                                ) : (
                                    fareLabel(departure.fare, money)
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
                                                    aria-label={`Actions for ${departure.leavesAt}`}
                                                />
                                            }
                                        >
                                            <Ellipsis />
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            <DropdownMenuItem
                                                onClick={() =>
                                                    onEdit(departure)
                                                }
                                            >
                                                Edit
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                onClick={() =>
                                                    onDelete(departure)
                                                }
                                            >
                                                Delete
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}
