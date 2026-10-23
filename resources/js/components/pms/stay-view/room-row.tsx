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
import type { TimelineRow } from './group-rows';

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
        <div className="flex min-w-0 items-center gap-2 text-sm">
            <span className="min-w-0 flex-1 truncate">
                <span className={cn('font-medium', isBed && 'pl-3')}>
                    {isBed ? `Bed ${room.label}` : room.label}
                </span>{' '}
                <span className="text-xs text-grid-muted-foreground">
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
            className="flex min-w-0 flex-1 items-center gap-1.5 rounded-sm text-left text-sm font-medium outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
            onClick={() => onToggle(row.groupKey)}
        >
            <Chevron aria-hidden className="size-4 shrink-0" />
            <span className="truncate">{row.title}</span>
            <span className="text-xs font-normal text-grid-muted-foreground tabular-nums">
                {row.count}
            </span>
        </button>
    );
}
