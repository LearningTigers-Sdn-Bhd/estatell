import { Link } from '@inertiajs/react';
import { Ellipsis, Info, Ship } from 'lucide-react';
import { EmptyState } from '@/components/pms/empty-state';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Popover,
    PopoverContent,
    PopoverDescription,
    PopoverHeader,
    PopoverTitle,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { mealPeriods as mealPeriodsRoute } from '@/routes/settings';

type Departure = App.Data.Boats.BoatDepartureData;
type Fare = App.Data.Boats.BoatFareData;
type MealPeriod = App.Data.Meals.MealPeriodData;

/**
 * The Meals header with an info button. The popover lists the active meal times and the rule, and links to the meal
 * periods, where a wrong result is fixed. A popover, not a hover card, so it also opens on a tap.
 */
function MealsHeader({ mealPeriods }: { mealPeriods: MealPeriod[] }) {
    return (
        <div className="flex items-center gap-1">
            Meals
            <Popover>
                <PopoverTrigger
                    render={
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-xs"
                            aria-label="Meal times"
                        />
                    }
                >
                    <Info />
                </PopoverTrigger>
                <PopoverContent className="w-72" align="end">
                    <PopoverHeader>
                        <PopoverTitle>Meal times</PopoverTitle>
                    </PopoverHeader>
                    {mealPeriods.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No meal periods yet.
                        </p>
                    ) : (
                        <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 text-sm">
                            {mealPeriods.map((mealPeriod) => (
                                <div key={mealPeriod.id} className="contents">
                                    <dt>{mealPeriod.name}</dt>
                                    <dd className="tabular-nums">
                                        {mealPeriod.startsAt}–
                                        {mealPeriod.endsAt}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    )}
                    <PopoverDescription>
                        Boat in: meals that end after the boat arrives. Boat
                        out: meals that start before the boat leaves.
                    </PopoverDescription>
                    <Link
                        href={mealPeriodsRoute()}
                        className={buttonVariants({
                            variant: 'link',
                            size: 'sm',
                            className: 'h-auto self-start p-0',
                        })}
                    >
                        Edit meal periods →
                    </Link>
                </PopoverContent>
            </Popover>
        </div>
    );
}

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
    mealPeriods,
    emptyTitle,
    money,
    togglingIds,
    onToggleActive,
    onEdit,
    onDelete,
}: {
    departures: Departure[];
    mealPeriods: MealPeriod[];
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
                        <TableHead className="w-64">Fare</TableHead>
                        <TableHead className="w-64">
                            <MealsHeader mealPeriods={mealPeriods} />
                        </TableHead>
                        <TableHead className="w-14">
                            <span className="sr-only">Actions</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {departures.length === 0 && (
                        <TableRow className="hover:bg-transparent">
                            <TableCell colSpan={8}>
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
                            <TableCell className="whitespace-normal">
                                {departure.meals.length === 0 ? (
                                    <span className="text-muted-foreground">
                                        —
                                    </span>
                                ) : (
                                    departure.meals.join(' · ')
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
