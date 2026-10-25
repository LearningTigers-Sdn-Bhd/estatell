import {
    BellOff,
    ChevronDown,
    ChevronRight,
    CircleCheck,
    ShieldCheck,
    SprayCan,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { LaneRow, TimelineRow } from './group-rows';

type Room = App.Data.StayView.RoomRowData;

const HOUSEKEEPING: Record<
    App.Enums.HousekeepingStatus,
    { icon: LucideIcon; label: string; className: string }
> = {
    clean: { icon: CircleCheck, label: 'Clean', className: 'text-success' },
    inspected: {
        icon: ShieldCheck,
        label: 'Inspected',
        className: 'text-info',
    },
    dirty: { icon: SprayCan, label: 'Dirty', className: 'text-warning' },
};

/** The label of a room or bed row: its name, its type and the housekeeping status with a text alternative. */
export function RoomLabel({ room }: { room: Room }) {
    const status = HOUSEKEEPING[room.housekeepingStatus];
    const Icon = status.icon;
    const isBed = room.bedId !== null;

    return (
        <div className="flex min-w-0 items-center gap-2">
            <span className="flex min-w-0 flex-1 flex-col">
                <span className={cn('truncate text-sm', isBed && 'pl-3')}>
                    {isBed ? `Bed ${room.label}` : room.label}
                </span>
                <span
                    className={cn(
                        'truncate text-xs text-grid-muted-foreground',
                        isBed && 'pl-3',
                    )}
                >
                    {isBed ? room.roomNumber : room.roomTypeName}
                </span>
            </span>
            {room.doNotDisturb && (
                <BellOff
                    aria-label="Do not disturb"
                    className="size-3.5 shrink-0 text-grid-muted-foreground"
                />
            )}
            <Icon
                aria-label={status.label}
                className={cn('size-4 shrink-0', status.className)}
            />
        </div>
    );
}

/**
 * The label of a row of an unassigned lane: the room type and, on the first row, how many units wait for a room.
 * The lane is not a room, so it has no housekeeping status.
 */
export function LaneLabel({ lane }: { lane: LaneRow['lane'] }) {
    return (
        <span className="flex min-w-0 flex-col">
            <span className="truncate text-sm">
                Unassigned · {lane.roomTypeName}
            </span>
            {lane.units !== null && (
                <span className="truncate text-xs text-grid-muted-foreground tabular-nums">
                    {lane.units} {lane.units === 1 ? 'unit' : 'units'}
                </span>
            )}
        </span>
    );
}

/** The heading of a group of rows, with a button that folds it. */
export function GroupLabel({
    row,
    onToggle,
}: {
    row: Extract<TimelineRow, { kind: 'group' }>;
    onToggle: (groupKey: string) => void;
}) {
    const Chevron = row.collapsed ? ChevronRight : ChevronDown;

    return (
        <button
            type="button"
            aria-expanded={!row.collapsed}
            title={row.collapsed ? 'Expand' : 'Collapse'}
            className="flex w-full min-w-0 items-center gap-2 rounded-md text-left outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
            onClick={() => onToggle(row.groupKey)}
        >
            <Chevron aria-hidden className="size-4 shrink-0" />
            <span className="truncate text-sm font-semibold">{row.title}</span>
            {row.collapsed && (
                <span className="ml-auto shrink-0 text-xs text-grid-muted-foreground tabular-nums">
                    {row.count}
                </span>
            )}
        </button>
    );
}
