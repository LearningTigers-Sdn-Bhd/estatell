import { Ban, Info } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
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
                    {preferences.map((preference) => (
                        <TableRow key={preference.type}>
                            <TableHead
                                scope="row"
                                className="px-4 font-normal text-foreground"
                            >
                                {preference.label}
                            </TableHead>
                            {preference.channels.map((cell) => {
                                const key = preferenceKey(
                                    preference.type,
                                    cell.channel,
                                );
                                const isPending = pending.has(key);
                                const switchId = `notification-${preference.type}-${cell.channel}`;

                                return (
                                    <TableCell
                                        key={cell.channel}
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
                                                    icon={Ban}
                                                />
                                            </span>
                                        ) : (
                                            <Field
                                                orientation="horizontal"
                                                className="w-16 justify-start gap-2"
                                                aria-busy={isPending}
                                            >
                                                <Switch
                                                    id={switchId}
                                                    checked={cell.enabled}
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
                            })}
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}
