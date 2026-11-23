import { Link } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Ellipsis, ExternalLink, Tent } from 'lucide-react';
import { EmptyState } from '@/components/pms/empty-state';
import { MoneyText } from '@/components/pms/money-text';
import { Switch } from '@/components/ui/switch';
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
import { show } from '@/routes/settings/facilities';

type Facility = App.Data.Facilities.FacilityData;
type Option = { value: string; label: string };

function labelOf(options: Option[], value: string): string {
    return options.find((option) => option.value === value)?.label ?? value;
}

/**
 * The Facilities settings list in its saved order. Facilities that are off stay in place and are muted. With no
 * facilities the table keeps its header and shows the empty state in one row.
 */
export function FacilityTable({
    facilities,
    sharingOptions,
    capacityUnits,
    rateBases,
    currency,
    busyIds,
    onMove,
    onToggleActive,
}: {
    facilities: Facility[];
    sharingOptions: Option[];
    capacityUnits: Option[];
    rateBases: Option[];
    currency: App.Data.Property.CurrencyData | null;
    busyIds: Set<number>;
    onMove: (from: number, to: number) => void;
    onToggleActive: (facility: Facility, active: boolean) => void;
}) {
    return (
        <div className="overflow-auto rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead className="w-28">Available</TableHead>
                        <TableHead>Name</TableHead>
                        <TableHead>Sharing</TableHead>
                        <TableHead className="text-right">Capacity</TableHead>
                        <TableHead className="text-right">
                            Rate{currency ? ` (${currency.code})` : ''}
                        </TableHead>
                        <TableHead className="w-12">
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
                    {facilities.map((facility, position) => (
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
                                    href={show.url({ facility: facility.id })}
                                    className="underline-offset-4 hover:underline"
                                >
                                    {facility.name}
                                </Link>
                            </TableCell>
                            <TableCell>
                                {labelOf(sharingOptions, facility.sharing)}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {facility.capacity}{' '}
                                {labelOf(capacityUnits, facility.capacityUnit)}
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
                                            {labelOf(
                                                rateBases,
                                                facility.rateBasis,
                                            ).toLowerCase()}
                                        </span>
                                    </>
                                )}
                            </TableCell>
                            <TableCell>
                                <DropdownMenu>
                                    <DropdownMenuTrigger
                                        render={
                                            <Button
                                                variant="ghost"
                                                size="icon-sm"
                                            />
                                        }
                                        aria-label={`Actions for ${facility.name}`}
                                        disabled={busyIds.has(facility.id)}
                                    >
                                        <Ellipsis />
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                        align="end"
                                        className="w-44"
                                    >
                                        <DropdownMenuItem
                                            render={
                                                <Link
                                                    href={show.url({
                                                        facility: facility.id,
                                                    })}
                                                />
                                            }
                                        >
                                            <ExternalLink />
                                            Open
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            disabled={position === 0}
                                            onClick={() =>
                                                onMove(position, position - 1)
                                            }
                                        >
                                            <ArrowUp />
                                            Move up
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            disabled={
                                                position ===
                                                facilities.length - 1
                                            }
                                            onClick={() =>
                                                onMove(position, position + 1)
                                            }
                                        >
                                            <ArrowDown />
                                            Move down
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}
