import { Ellipsis } from 'lucide-react';
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
 * One boat direction's departures, sorted by the server. Unavailable rows stay in place and are muted.
 */
export function BoatDepartureTable({
    departures,
    money,
    togglingIds,
    onToggleActive,
    onEdit,
    onDelete,
}: {
    departures: Departure[];
    money: Intl.NumberFormat;
    togglingIds: Set<number>;
    onToggleActive: (departure: Departure, active: boolean) => void;
    onEdit: (departure: Departure) => void;
    onDelete: (departure: Departure) => void;
}) {
    return (
        <div className="overflow-auto rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Available</TableHead>
                        <TableHead>Leaves</TableHead>
                        <TableHead>Arrives</TableHead>
                        <TableHead>Pick-up point</TableHead>
                        <TableHead className="text-right">Seats</TableHead>
                        <TableHead>Fare</TableHead>
                        <TableHead>
                            <span className="sr-only">Actions</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
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
