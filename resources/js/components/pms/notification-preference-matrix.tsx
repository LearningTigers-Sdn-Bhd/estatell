import {
    ArchiveX,
    Ban,
    BanknoteX,
    BedDouble,
    Bell,
    CalendarClock,
    CalendarPlus,
    CalendarX,
    ChevronDown,
    ChevronRight,
    CircleCheck,
    CircleX,
    Gauge,
    IdCard,
    Info,
    LifeBuoy,
    ListChecks,
    MapPinOff,
    MessagesSquare,
    Receipt,
    Rocket,
    TriangleAlert,
    Undo2,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Fragment, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

type Preference = App.Data.Notifications.NotificationPreferenceData;
type Cell = App.Data.Notifications.NotificationChannelPreferenceData;

/** Icon names sent by `App\Enums\NotificationType::icon()`. */
const icons: Record<string, LucideIcon> = {
    'archive-x': ArchiveX,
    'banknote-x': BanknoteX,
    'bed-double': BedDouble,
    'calendar-clock': CalendarClock,
    'calendar-plus': CalendarPlus,
    'calendar-x': CalendarX,
    'circle-check': CircleCheck,
    'circle-x': CircleX,
    gauge: Gauge,
    'id-card': IdCard,
    'life-buoy': LifeBuoy,
    'list-checks': ListChecks,
    'map-pin-off': MapPinOff,
    'messages-square': MessagesSquare,
    receipt: Receipt,
    rocket: Rocket,
    'triangle-alert': TriangleAlert,
    'undo-2': Undo2,
};

/** The preferences in the order the server sends them, one group per heading. */
function byGroup(preferences: Preference[]): [string, Preference[]][] {
    const groups = new Map<string, Preference[]>();
    for (const preference of preferences) {
        groups.set(preference.group, [
            ...(groups.get(preference.group) ?? []),
            preference,
        ]);
    }

    return [...groups.entries()];
}

export const preferenceKey = (
    type: Preference['type'],
    channel: Cell['channel'],
) => `${type}:${channel}`;

export function updatePreferenceMatrix(
    preferences: Preference[],
    type: Preference['type'],
    channel: Cell['channel'],
    enabled: boolean,
) {
    return preferences.map((preference) =>
        preference.type === type
            ? {
                  ...preference,
                  channels: preference.channels.map((cell) =>
                      cell.channel === channel ? { ...cell, enabled } : cell,
                  ),
              }
            : preference,
    );
}

function PreferenceInfo({
    label,
    reason,
    icon: Icon = Info,
}: {
    label: string;
    reason: string;
    icon?: LucideIcon;
}) {
    return (
        <Tooltip>
            <TooltipTrigger
                render={<Button variant="ghost" size="icon-xs" />}
                aria-label={`${label}: ${reason}`}
            >
                <Icon />
            </TooltipTrigger>
            <TooltipContent>{reason}</TooltipContent>
        </Tooltip>
    );
}

export function NotificationPreferenceMatrix({
    preferences,
    pending,
    onChange,
}: {
    preferences: Preference[];
    pending: ReadonlySet<string>;
    onChange: (
        type: Preference['type'],
        channel: Cell['channel'],
        enabled: boolean,
    ) => void;
}) {
    const channels = preferences[0]?.channels ?? [];
    const [collapsed, setCollapsed] = useState<ReadonlySet<string>>(new Set());
    const toggleGroup = (group: string) =>
        setCollapsed((current) => {
            const next = new Set(current);
            if (!next.delete(group)) {
                next.add(group);
            }

            return next;
        });

    return (
        <div className="overflow-hidden rounded-xl border">
            <Table className="table-fixed">
                <caption className="sr-only">
                    Notification delivery preferences by type and channel
                </caption>
                <TableHeader>
                    <TableRow>
                        <TableHead className="min-w-56 px-4">
                            Notification
                        </TableHead>
                        {channels.map((channel) => (
                            <TableHead
                                key={channel.channel}
                                className="w-32 text-left"
                            >
                                {channel.label}
                            </TableHead>
                        ))}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {byGroup(preferences).map(([group, rows]) => {
                        const isCollapsed = collapsed.has(group);

                        return (
                            <Fragment key={group}>
                                <TableRow className="bg-muted/50 hover:bg-muted/50">
                                    <TableHead
                                        scope="colgroup"
                                        colSpan={channels.length + 1}
                                        className="h-auto px-4 py-2 font-normal text-foreground"
                                    >
                                        <button
                                            type="button"
                                            className="flex items-center gap-2 rounded-sm text-left font-medium outline-none focus-visible:ring-2 focus-visible:ring-ring [&_svg]:size-4"
                                            aria-expanded={!isCollapsed}
                                            onClick={() => toggleGroup(group)}
                                        >
                                            {isCollapsed ? (
                                                <ChevronRight aria-hidden />
                                            ) : (
                                                <ChevronDown aria-hidden />
                                            )}
                                            {group}
                                            <Badge variant="outline">
                                                {rows.length}
                                            </Badge>
                                        </button>
                                    </TableHead>
                                </TableRow>
                                {!isCollapsed &&
                                    rows.map((preference) => {
                                        const Icon =
                                            icons[preference.icon] ?? Bell;

                                        return (
                                            <TableRow key={preference.type}>
                                                <TableHead
                                                    scope="row"
                                                    className="h-auto px-4 py-3 font-normal whitespace-normal text-foreground"
                                                >
                                                    <span className="flex items-start gap-3">
                                                        <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                                            <Icon
                                                                aria-hidden
                                                                className="size-4"
                                                            />
                                                        </span>
                                                        <span className="flex min-w-0 flex-col gap-0.5">
                                                            <span className="font-medium">
                                                                {
                                                                    preference.label
                                                                }
                                                            </span>
                                                            <span className="text-muted-foreground">
                                                                {
                                                                    preference.description
                                                                }
                                                            </span>
                                                        </span>
                                                    </span>
                                                </TableHead>
                                                {preference.channels.map(
                                                    (cell) => {
                                                        const key =
                                                            preferenceKey(
                                                                preference.type,
                                                                cell.channel,
                                                            );
                                                        const isPending =
                                                            pending.has(key);
                                                        const switchId = `notification-${preference.type}-${cell.channel}`;

                                                        return (
                                                            <TableCell
                                                                key={
                                                                    cell.channel
                                                                }
                                                                className="w-32"
                                                            >
                                                                {!cell.supported ? (
                                                                    <span className="flex w-16 justify-start">
                                                                        <PreferenceInfo
                                                                            label={`${preference.label} by ${cell.label}`}
                                                                            reason={
                                                                                cell.disabledReason ??
                                                                                'Not available'
                                                                            }
                                                                            icon={
                                                                                Ban
                                                                            }
                                                                        />
                                                                    </span>
                                                                ) : (
                                                                    <Field
                                                                        orientation="horizontal"
                                                                        className="w-16 justify-start gap-2"
                                                                        aria-busy={
                                                                            isPending
                                                                        }
                                                                    >
                                                                        <Switch
                                                                            id={
                                                                                switchId
                                                                            }
                                                                            checked={
                                                                                cell.enabled
                                                                            }
                                                                            disabled={
                                                                                !cell.editable ||
                                                                                isPending
                                                                            }
                                                                            aria-label={`${preference.label} by ${cell.label}`}
                                                                            onCheckedChange={(
                                                                                enabled,
                                                                            ) =>
                                                                                onChange(
                                                                                    preference.type,
                                                                                    cell.channel,
                                                                                    enabled,
                                                                                )
                                                                            }
                                                                        />
                                                                        {cell.disabledReason && (
                                                                            <PreferenceInfo
                                                                                label={`${preference.label} by ${cell.label}`}
                                                                                reason={
                                                                                    cell.disabledReason
                                                                                }
                                                                            />
                                                                        )}
                                                                    </Field>
                                                                )}
                                                            </TableCell>
                                                        );
                                                    },
                                                )}
                                            </TableRow>
                                        );
                                    })}
                            </Fragment>
                        );
                    })}
                </TableBody>
            </Table>
        </div>
    );
}
