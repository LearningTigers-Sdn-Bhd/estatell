import { Ban, BellOff, Diamond, Plus, SprayCan } from 'lucide-react';
import { DateText } from '@/components/pms/date-text';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { CellAction } from './bookable-cell';
import type { CardControls } from './card-controls';
import type { RoomDay, RoomDayStay } from './room-day';
import { PaxCount } from './pax-count';
import { HOUSEKEEPING } from './room-row';

type BadgeData = App.Data.Shared.BadgeData;

/** The tint of a state box and the color of its label, by the tone of the state. */
const TONE_BOX: Record<App.Enums.Tone, { box: string; label: string }> = {
    neutral: {
        box: 'border-transparent bg-muted',
        label: 'text-muted-foreground',
    },
    info: { box: 'border-info/30 bg-info/10', label: 'text-info' },
    success: { box: 'border-success/30 bg-success/10', label: 'text-success' },
    warning: {
        box: 'border-warning/30 bg-warning/10',
        label: 'text-warning-text',
    },
    danger: {
        box: 'border-destructive/30 bg-destructive/10',
        label: 'text-destructive',
    },
};

const BOX =
    'flex min-h-16 min-w-0 flex-1 flex-col gap-0.5 rounded-md border px-2.5 py-2 text-left';

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

/** The label of a state box in the tone of its state. */
function StateLabel({ status }: { status: BadgeData | undefined }) {
    return (
        <span
            className={cn(
                'text-xs font-medium',
                status && TONE_BOX[status.tone].label,
            )}
        >
            {status?.label}
        </span>
    );
}

/** A state box for one stay. It opens the stay in the peek sheet. */
function StayBox({
    line,
    status,
    onOpenStay,
}: {
    line: RoomDayStay;
    status: BadgeData | undefined;
    onOpenStay: (unitId: number) => void;
}) {
    const { stay } = line;

    return (
        <button
            type="button"
            className={cn(
                BOX,
                status && TONE_BOX[status.tone].box,
                'outline-none focus-visible:ring-3 focus-visible:ring-ring/50',
            )}
            onClick={() => onOpenStay(stay.unitId)}
        >
            <StateLabel status={status} />
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
                <span className="shrink-0 text-xs text-muted-foreground">
                    <PaxCount stay={stay} size="sm" />
                </span>
            </span>
            <span className="flex min-w-0 flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                <span className="tabular-nums">{whenLabel(line)}</span>
                {stay.balanceDue && (
                    <span className="font-medium text-warning-text">
                        Balance due
                    </span>
                )}
            </span>
        </button>
    );
}

/**
 * One room or bed on the date of the Room View. The header holds the room, the type and the housekeeping chip. Under it
 * is a state box tinted with the tone of the state: one for each stay (it opens the stay), the block, or Vacant. The
 * footer holds the actions and quick changes that apply (`actions` from `cellActions()`, `controls` from
 * `cardControls()`); a card with none has no footer.
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
    badges: Record<string, BadgeData>;
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
    const hasFooter =
        actions.length > 0 || controls.markClean || controls.doNotDisturb;

    return (
        <li className="flex flex-col overflow-hidden rounded-lg border bg-card text-sm text-card-foreground">
            <div className="flex flex-1 flex-col gap-2 p-3">
                <div className="flex min-w-0 items-start gap-2">
                    <span className="flex min-w-0 flex-1 flex-col">
                        <span className="truncate text-base font-semibold">
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
                    <Badge variant="outline">
                        <Icon aria-hidden className={housekeeping.className} />
                        {housekeeping.label}
                    </Badge>
                </div>
                {day.stays.map((line) => (
                    <StayBox
                        key={line.stay.unitId}
                        line={line}
                        status={badges[line.state]}
                        onOpenStay={onOpenStay}
                    />
                ))}
                {day.block && (
                    <div
                        className={cn(
                            BOX,
                            TONE_BOX[badges.blocked?.tone ?? 'danger'].box,
                        )}
                    >
                        <StateLabel status={badges.blocked} />
                        <span className="font-medium">
                            {day.block.reasonLabel}
                        </span>
                        <span className="text-xs text-muted-foreground">
                            Until <DateText date={day.block.lastNight} />
                        </span>
                    </div>
                )}
                {day.stays.length === 0 && !day.block && (
                    <div className={cn(BOX, TONE_BOX.neutral.box)}>
                        <StateLabel status={badges.vacant} />
                    </div>
                )}
            </div>
            {hasFooter && (
                <div className="flex divide-x border-t">
                    {actions.includes('booking') && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="flex-1 rounded-none"
                            onClick={() => onBook(room)}
                        >
                            <Plus data-icon="inline-start" />
                            New
                        </Button>
                    )}
                    {actions.includes('block') && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="flex-1 rounded-none"
                            onClick={() => onBlock(room)}
                        >
                            <Ban data-icon="inline-start" />
                            Block
                        </Button>
                    )}
                    {controls.markClean && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="flex-1 rounded-none"
                            onClick={() => onMarkClean(room)}
                        >
                            <SprayCan data-icon="inline-start" />
                            Clean
                        </Button>
                    )}
                    {controls.doNotDisturb && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="flex-1 rounded-none"
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
        </li>
    );
}
