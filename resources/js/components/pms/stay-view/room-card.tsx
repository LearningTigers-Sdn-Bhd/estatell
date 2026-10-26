import { Ban, BellOff, Diamond, Plus, SprayCan } from 'lucide-react';
import { DateText } from '@/components/pms/date-text';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { CellAction } from './bookable-cell';
import type { CardControls } from './card-controls';
import type { RoomDay, RoomDayStay } from './room-day';
import { HOUSEKEEPING } from './room-row';

type Badge = App.Data.Shared.BadgeData;

function paxLabel(stay: App.Data.StayView.StayData): string {
    return stay.children > 0
        ? `${stay.adults}A ${stay.children}C`
        : `${stay.adults}A`;
}

/** Departing and Arriving show the time of the day; a guest who stays on shows the departure date. */
function whenLabel({ stay, state }: RoomDayStay): React.ReactNode {
    if (state === 'departing') {
        return <>Out {stay.departureTime}</>;
    }

    if (state === 'arriving') {
        return <>In {stay.arrivalTime}</>;
    }

    return (
        <>
            Out <DateText date={stay.departureDate} />
        </>
    );
}

function StayLine({
    line,
    stretched,
    onOpenStay,
}: {
    line: RoomDayStay;
    /** Make the whole card the click target. Used when the card holds one stay. */
    stretched: boolean;
    onOpenStay: (unitId: number) => void;
}) {
    const { stay } = line;

    return (
        <button
            type="button"
            className={cn(
                'flex w-full min-w-0 flex-col gap-0.5 rounded-sm border-l-4 py-0.5 pl-2 text-left outline-none focus-visible:ring-3 focus-visible:ring-ring/50',
                stay.balanceDue ? 'border-warning' : 'border-transparent',
                stretched && 'after:absolute after:inset-0 after:rounded-lg',
            )}
            onClick={() => onOpenStay(stay.unitId)}
        >
            <span className="flex min-w-0 items-center gap-1.5">
                {stay.groupName && (
                    <Diamond
                        aria-label={`Group ${stay.groupName}`}
                        className="size-3 shrink-0 fill-current text-muted-foreground"
                    />
                )}
                <span className="truncate font-medium">
                    {stay.guestLabel ?? 'No guest named'}
                </span>
                <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                    {paxLabel(stay)}
                </span>
            </span>
            <span className="flex min-w-0 items-center gap-2 text-xs text-muted-foreground">
                <span className="tabular-nums">{whenLabel(line)}</span>
                {stay.balanceDue && (
                    <span className="font-medium text-warning">
                        Balance due
                    </span>
                )}
            </span>
        </button>
    );
}

/**
 * One room or bed on the date of the Room View: the room and type, the housekeeping status, what the room does
 * (Departing, In house, Arriving, Blocked or Vacant) and a line for each stay. A card with one stay opens it from
 * anywhere on the card; with two, each line opens its own. A vacant card shows its state and the actions the user may
 * run on it (`actions`, from `cellActions()`); its body takes no click.
 */
export function RoomCard({
    day,
    badges,
    actions,
    controls,
    onOpenStay,
    onBook,
    onBlock,
    onMarkClean,
    onToggleDnd,
}: {
    day: RoomDay;
    badges: Record<string, Badge>;
    /** What a vacant card offers. Empty for other cards, a past date or a user with neither permission. */
    actions: CellAction[];
    onOpenStay: (unitId: number) => void;
    onBook: (room: App.Data.StayView.RoomRowData) => void;
    onBlock: (room: App.Data.StayView.RoomRowData) => void;
    /** The quick changes this card shows, from `cardControls()`. */
    controls: CardControls;
    onMarkClean: (room: App.Data.StayView.RoomRowData) => void;
    onToggleDnd: (room: App.Data.StayView.RoomRowData) => void;
}) {
    const { room } = day;
    const housekeeping = HOUSEKEEPING[room.housekeepingStatus];
    const Icon = housekeeping.icon;
    const isBed = room.bedId !== null;

    return (
        <li className="relative flex flex-col gap-2 rounded-lg border bg-card p-3 text-sm text-card-foreground">
            <div className="flex min-w-0 items-start gap-2">
                <span className="flex min-w-0 flex-1 flex-col">
                    <span className="truncate font-semibold">
                        {isBed ? `Bed ${room.label}` : room.label}
                    </span>
                    <span className="truncate text-xs text-muted-foreground">
                        {isBed ? room.roomNumber : room.roomTypeName}
                    </span>
                </span>
                {room.isPriority && (
                    <span
                        role="img"
                        aria-label="Priority"
                        title="Must be ready today"
                        className="font-bold text-warning"
                    >
                        !
                    </span>
                )}
                {room.doNotDisturb && (
                    <BellOff
                        aria-label="Do not disturb"
                        className="size-4 shrink-0 text-muted-foreground"
                    />
                )}
                <span className="flex shrink-0 items-center gap-1 text-xs">
                    <Icon
                        aria-hidden
                        className={cn('size-4', housekeeping.className)}
                    />
                    {housekeeping.label}
                </span>
            </div>
            <div className="flex flex-wrap gap-1">
                {day.states.map((state) => (
                    <StatusBadge
                        key={state}
                        status={badges[state]}
                        dot={false}
                    />
                ))}
            </div>
            {day.stays.map((line) => (
                <StayLine
                    key={line.stay.unitId}
                    line={line}
                    stretched={day.stays.length === 1}
                    onOpenStay={onOpenStay}
                />
            ))}
            {(controls.markClean || controls.doNotDisturb) && (
                <div className="flex flex-wrap gap-1">
                    {controls.markClean && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => onMarkClean(room)}
                        >
                            <SprayCan data-icon="inline-start" />
                            Mark clean
                        </Button>
                    )}
                    {controls.doNotDisturb && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            aria-pressed={room.doNotDisturb}
                            onClick={() => onToggleDnd(room)}
                        >
                            <BellOff data-icon="inline-start" />
                            {room.doNotDisturb
                                ? 'Clear do not disturb'
                                : 'Do not disturb'}
                        </Button>
                    )}
                </div>
            )}
            {actions.length > 0 && (
                <div className="flex flex-wrap gap-1">
                    {actions.includes('booking') && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => onBook(room)}
                        >
                            <Plus data-icon="inline-start" />
                            New booking
                        </Button>
                    )}
                    {actions.includes('block') && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => onBlock(room)}
                        >
                            <Ban data-icon="inline-start" />
                            Block room
                        </Button>
                    )}
                </div>
            )}
            {day.block && (
                <p className="flex flex-col text-xs text-muted-foreground">
                    <span className="font-medium text-foreground">
                        {day.block.reasonLabel}
                    </span>
                    <span>
                        Until <DateText date={day.block.lastNight} />
                    </span>
                </p>
            )}
        </li>
    );
}
