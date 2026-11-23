import { router } from '@inertiajs/react';
import { BedSingle, ChevronDown } from 'lucide-react';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
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
import { status as bedStatus } from '@/routes/housekeeping/rooms/beds';

type Room = App.Data.Housekeeping.HousekeepingRoomData;
type Bed = App.Data.Housekeeping.HousekeepingBedData;
type Badge = App.Data.Shared.BadgeData;

/** "3 of 8 beds dirty", or how many beds are all clean or inspected. */
function summary(beds: Bed[]): string {
    const dirty = beds.filter((bed) => bed.status.value === 'dirty').length;

    if (dirty > 0) {
        return `${dirty} of ${beds.length} beds dirty`;
    }

    return beds.every((bed) => bed.status.value === 'inspected')
        ? `${beds.length} beds inspected`
        : `${beds.length} beds ready`;
}

/**
 * A dorm's beds on the housekeeping board: only the bunks a guest left need making up, so each bed has its own status
 * and menu. The dorm's own status is the summary the server works out from them. See
 * docs/19-housekeeping/04-dorm-beds.md.
 */
export function DormBeds({ room }: { room: Room }) {
    function move(bed: Bed, next: Badge) {
        router
            .optimistic<{ rooms: Room[] }>((props) => ({
                rooms: props.rooms.map((item) =>
                    item.id === room.id
                        ? {
                              ...item,
                              beds: item.beds.map((candidate) =>
                                  candidate.id === bed.id
                                      ? {
                                            ...candidate,
                                            status: next,
                                            moves: [],
                                        }
                                      : candidate,
                              ),
                          }
                        : item,
                ),
            }))
            .put(
                bedStatus.url({ room: room.id, bed: bed.id }),
                { status: next.value },
                { preserveScroll: true },
            );
    }

    return (
        <Popover>
            <PopoverTrigger
                render={
                    <Button
                        variant="ghost"
                        size="sm"
                        className="mt-1 h-7 gap-1 px-1.5 text-xs"
                    />
                }
            >
                <BedSingle data-icon="inline-start" />
                {summary(room.beds)}
                <ChevronDown className="size-3.5 text-muted-foreground" />
            </PopoverTrigger>
            <PopoverContent align="start" className="w-80">
                <PopoverHeader>
                    <PopoverTitle>Beds in {room.number}</PopoverTitle>
                    <PopoverDescription>
                        Make up the beds guests left. The dorm is clean when
                        every bed is.
                    </PopoverDescription>
                </PopoverHeader>
                <ul className="flex flex-col divide-y">
                    {room.beds.map((bed) => (
                        <li
                            key={bed.id}
                            className="flex items-center justify-between gap-2 py-2"
                        >
                            <div className="grid min-w-0 gap-0.5">
                                <span className="text-sm font-medium">
                                    Bed {bed.label}
                                </span>
                                <span className="flex items-center gap-1.5 truncate text-xs text-muted-foreground">
                                    {bed.stay && (
                                        <StatusBadge status={bed.stay} />
                                    )}
                                    {bed.guest ?? (bed.stay ? null : 'Empty')}
                                </span>
                            </div>
                            {bed.moves.length > 0 ? (
                                <DropdownMenu>
                                    <DropdownMenuTrigger
                                        className="flex shrink-0 items-center gap-1 rounded-md outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                                        aria-label={`Bed ${bed.label} is ${bed.status.label}. Change it`}
                                    >
                                        <StatusBadge status={bed.status} />
                                        <ChevronDown
                                            aria-hidden
                                            className="size-3.5 text-muted-foreground"
                                        />
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        {bed.moves.map((next) => (
                                            <DropdownMenuItem
                                                key={next.value}
                                                aria-label={`Change bed ${bed.label} to ${next.label}`}
                                                onClick={() => move(bed, next)}
                                            >
                                                <StatusBadge status={next} />
                                            </DropdownMenuItem>
                                        ))}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            ) : (
                                <StatusBadge status={bed.status} />
                            )}
                        </li>
                    ))}
                </ul>
            </PopoverContent>
        </Popover>
    );
}
