import { Deferred, Head, router } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    CircleDot,
    CircleX,
    DoorOpen,
    IdCard,
    KeyRound,
    RefreshCw,
    ShieldAlert,
} from 'lucide-react';
import { Fragment, useState } from 'react';
import DoorLockSettingsController from '@/actions/App/Http/Controllers/DoorLocks/DoorLockSettingsController';
import { DateRangePicker } from '@/components/pms/date-range-picker';
import { PageHeader } from '@/components/pms/page-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@/components/ui/empty';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { reportDatePresets } from '@/lib/date-presets';
import { cn } from '@/lib/utils';
import { profile } from '@/routes/settings';
import { edit } from '@/routes/settings/door-locks';

type Kind = 'opened' | 'failed' | 'alarm' | 'other';

type LockEvent = {
    at: string;
    kind: Kind;
    event: string;
    /** Booking reference, PIN or card label, "Unknown PIN", or the TTLock user. */
    who: string | null;
    /** The booker's name, under the booking reference. */
    guest: string | null;
    method: 'pin' | 'card' | null;
    code: string | null;
};

type Log = { events: LockEvent[]; error: string | null };

const KINDS: Record<
    Kind,
    { label: string; icon: LucideIcon; className: string }
> = {
    opened: { label: 'Opened', icon: DoorOpen, className: 'text-success' },
    failed: { label: 'Failed', icon: CircleX, className: 'text-warning' },
    alarm: {
        label: 'Alarms',
        icon: ShieldAlert,
        className: 'text-destructive',
    },
    other: {
        label: 'Other',
        icon: CircleDot,
        className: 'text-muted-foreground',
    },
};

function time(iso: string): string {
    return new Date(iso).toLocaleTimeString('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
    });
}

function dayLabel(iso: string): string {
    const day = new Date(iso);
    const yesterday = new Date();
    yesterday.setDate(yesterday.getDate() - 1);

    if (day.toDateString() === new Date().toDateString()) {
        return 'Today';
    }

    if (day.toDateString() === yesterday.toDateString()) {
        return 'Yesterday';
    }

    return day.toLocaleDateString('en-GB', {
        weekday: 'short',
        day: '2-digit',
        month: 'short',
    });
}

/** One table, a header row per day, and a kind filter with counts above it. */
function LogBody({ log }: { log: Log }) {
    const [filter, setFilter] = useState<Kind | 'all'>('all');

    if (log.error !== null) {
        return (
            <Alert variant="destructive">
                <AlertTitle>Could not read the log from TTLock</AlertTitle>
                <AlertDescription>{log.error}</AlertDescription>
            </Alert>
        );
    }

    if (log.events.length === 0) {
        return (
            <Empty className="border">
                <EmptyHeader>
                    <EmptyTitle>No events</EmptyTitle>
                    <EmptyDescription>
                        TTLock has nothing for this lock on these dates.
                    </EmptyDescription>
                </EmptyHeader>
            </Empty>
        );
    }

    const count = (kind: Kind) =>
        log.events.filter((event) => event.kind === kind).length;
    const shown =
        filter === 'all'
            ? log.events
            : log.events.filter((event) => event.kind === filter);
    const days: { key: string; label: string; events: LockEvent[] }[] = [];

    for (const event of shown) {
        const key = new Date(event.at).toDateString();
        const last = days.at(-1);

        if (last?.key === key) {
            last.events.push(event);
        } else {
            days.push({ key, label: dayLabel(event.at), events: [event] });
        }
    }

    return (
        <div className="flex flex-col gap-3">
            <ToggleGroup
                variant="outline"
                size="sm"
                spacing={0}
                value={[filter]}
                onValueChange={(value) =>
                    value[0] && setFilter(value[0] as Kind | 'all')
                }
                aria-label="Show"
            >
                <ToggleGroupItem value="all">
                    All
                    <span className="text-muted-foreground tabular-nums">
                        {log.events.length}
                    </span>
                </ToggleGroupItem>
                {(Object.keys(KINDS) as Kind[]).map((kind) => {
                    const { label, icon: Icon, className } = KINDS[kind];

                    return (
                        <ToggleGroupItem key={kind} value={kind}>
                            <Icon aria-hidden className={className} />
                            {label}
                            <span
                                className={cn(
                                    'tabular-nums',
                                    count(kind) > 0 && kind !== 'other'
                                        ? cn('font-medium', className)
                                        : 'text-muted-foreground',
                                )}
                            >
                                {count(kind)}
                            </span>
                        </ToggleGroupItem>
                    );
                })}
            </ToggleGroup>

            {shown.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    None on these dates.
                </p>
            ) : (
                <div className="overflow-hidden rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-20">Time</TableHead>
                                <TableHead>Event</TableHead>
                                <TableHead>Who</TableHead>
                                <TableHead>PIN / card</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {days.map((day) => (
                                <Fragment key={day.key}>
                                    <TableRow className="bg-muted/50 hover:bg-muted/50">
                                        <TableCell
                                            colSpan={4}
                                            className="py-1.5 text-xs font-medium text-muted-foreground"
                                        >
                                            {day.label} · {day.events.length}{' '}
                                            {day.events.length === 1
                                                ? 'event'
                                                : 'events'}
                                        </TableCell>
                                    </TableRow>
                                    {day.events.map((event, index) => {
                                        const { icon: Icon, className } =
                                            KINDS[event.kind];
                                        const flagged =
                                            event.kind === 'failed' ||
                                            event.kind === 'alarm';

                                        return (
                                            <TableRow
                                                key={`${event.at}-${index}`}
                                                className={cn(
                                                    event.kind === 'alarm' &&
                                                        'bg-destructive/5',
                                                )}
                                            >
                                                <TableCell className="py-2 text-muted-foreground tabular-nums">
                                                    {time(event.at)}
                                                </TableCell>
                                                <TableCell className="py-2">
                                                    <span
                                                        className={cn(
                                                            'flex items-center gap-2',
                                                            flagged &&
                                                                cn(
                                                                    'font-medium',
                                                                    className,
                                                                ),
                                                        )}
                                                    >
                                                        <Icon
                                                            aria-hidden
                                                            className={cn(
                                                                'size-4 shrink-0',
                                                                className,
                                                            )}
                                                        />
                                                        {event.event}
                                                    </span>
                                                </TableCell>
                                                <TableCell className="max-w-64 py-2">
                                                    {event.who === null ? (
                                                        <span className="text-muted-foreground">
                                                            –
                                                        </span>
                                                    ) : (
                                                        <div className="flex min-w-0 flex-col">
                                                            <span
                                                                className={cn(
                                                                    'truncate',
                                                                    event.guest !==
                                                                        null &&
                                                                        'font-medium tabular-nums',
                                                                    event.code !==
                                                                        null &&
                                                                        event.guest ===
                                                                            null &&
                                                                        event.who.startsWith(
                                                                            'Unknown',
                                                                        ) &&
                                                                        'text-muted-foreground italic',
                                                                )}
                                                            >
                                                                {event.who}
                                                            </span>
                                                            {event.guest !==
                                                                null && (
                                                                <span className="truncate text-xs text-muted-foreground">
                                                                    {
                                                                        event.guest
                                                                    }
                                                                </span>
                                                            )}
                                                        </div>
                                                    )}
                                                </TableCell>
                                                <TableCell className="py-2">
                                                    {event.code === null ? (
                                                        <span className="text-muted-foreground">
                                                            –
                                                        </span>
                                                    ) : (
                                                        <span className="flex items-center gap-2">
                                                            <Badge
                                                                variant="secondary"
                                                                className={cn(
                                                                    'w-14 justify-center gap-1',
                                                                    event.method ===
                                                                        'card'
                                                                        ? 'bg-swatch-violet text-swatch-violet-foreground'
                                                                        : 'bg-swatch-blue text-swatch-blue-foreground',
                                                                )}
                                                            >
                                                                {event.method ===
                                                                'card' ? (
                                                                    <IdCard
                                                                        aria-hidden
                                                                    />
                                                                ) : (
                                                                    <KeyRound
                                                                        aria-hidden
                                                                    />
                                                                )}
                                                                {event.method ===
                                                                'card'
                                                                    ? 'Card'
                                                                    : 'PIN'}
                                                            </Badge>
                                                            <span className="font-mono text-muted-foreground tabular-nums">
                                                                {event.code}
                                                            </span>
                                                        </span>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </Fragment>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}
        </div>
    );
}

export default function DoorLockLog({
    lock,
    from,
    to,
    today,
    log,
}: {
    lock: App.Data.DoorLocks.DoorLockData;
    /** First date shown, `Y-m-d`, property time zone. */
    from: string;
    /** Last date shown, `Y-m-d`. */
    to: string;
    today: string;
    /** Deferred: read from TTLock after the page shows. */
    log?: Log;
}) {
    const [refreshing, setRefreshing] = useState(false);
    const place = [
        lock.roomNumber === null
            ? 'Not linked to a room'
            : `Room ${lock.roomNumber}`,
        lock.hasGateway ? (lock.gatewayName ?? 'Gateway') : 'No gateway',
    ].join(' · ');

    return (
        <>
            <Head title={`Access log · ${lock.name}`} />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title={`Access log · ${lock.name}`}
                    description={place}
                    back={{ href: edit(), label: 'Door locks' }}
                    actions={
                        <>
                            <DateRangePicker
                                from={from}
                                to={to}
                                onChange={(from, to) =>
                                    from &&
                                    to &&
                                    router.get(
                                        DoorLockSettingsController.log.url(
                                            { doorLock: lock.id },
                                            { query: { from, to } },
                                        ),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                                presets={reportDatePresets(today, [
                                    'today',
                                    'yesterday',
                                    'this-week',
                                    'last-week',
                                    'last-30-days',
                                ])}
                                maxDays={31}
                                weekStartsOn={1}
                                className="w-auto"
                            />
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                aria-label="Refresh"
                                disabled={refreshing}
                                focusableWhenDisabled
                                onClick={() =>
                                    router.reload({
                                        only: ['log'],
                                        onStart: () => setRefreshing(true),
                                        onFinish: () => setRefreshing(false),
                                    })
                                }
                            >
                                <RefreshCw
                                    className={cn(refreshing && 'animate-spin')}
                                />
                            </Button>
                        </>
                    }
                />

                {!lock.hasGateway && (
                    <Alert>
                        <AlertTitle>This lock has no gateway</AlertTitle>
                        <AlertDescription>
                            New events reach TTLock only when someone syncs the
                            lock in the TTLock app, so this log can be behind.
                        </AlertDescription>
                    </Alert>
                )}

                <Deferred
                    data="log"
                    fallback={
                        <div className="flex flex-col gap-3">
                            <Skeleton className="h-8 w-96" />
                            <Skeleton className="h-96" />
                        </div>
                    }
                >
                    {log && <LogBody key={`${from}-${to}`} log={log} />}
                </Deferred>
            </div>
        </>
    );
}

DoorLockLog.layout = {
    breadcrumbs: [
        { title: 'Settings', href: profile() },
        { title: 'Door locks', href: edit() },
        { title: 'Access log' },
    ],
};
